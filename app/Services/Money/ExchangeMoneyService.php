<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\FeeMode;
use App\Enums\Tax\TaxableType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\Handlers\ExchangeMoneyHandler;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxQuote;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;
use Transaction;
use Wallet;

/**
 * Moving money between two of a customer's own wallets.
 *
 * Lifted out of ExchangeMoneyController unchanged, so the app and the panel
 * cannot end up meaning different things by "exchange". The controller kept
 * the redirect and the flash message; everything that decides what happens to
 * the money is here.
 *
 * The work still happens inside one transaction with both wallets locked in id
 * order - the lock order is what stops two exchanges between the same pair
 * deadlocking - and it still refuses with NotifyErrorException, which both the
 * web panel and the mobile API already know how to render.
 *
 * @phpstan-type ExchangeLegs array{source: TransactionModel, destination: TransactionModel}
 */
final class ExchangeMoneyService
{
    public function __construct(
        private readonly ExchangeMoneyHandler $handler,
        private readonly TaxEngine $taxes = new TaxEngine,
    ) {}

    /**
     * The figures an exchange would produce, without producing it.
     *
     * Both the preview and the exchange itself call this, so a customer is
     * never shown one number and charged another. Nothing here writes, and
     * nothing here reads a figure from the caller: the rate and the fee come
     * from the wallets and the currency role every time.
     *
     * GF6: the source wallet owner's exchange tax is added on top of the
     * paying side in both fee modes - source_payable includes it, what
     * converts and what arrives do not change - and 'tax' is the quote.
     *
     * CR1: every figure is a decimal string, from the typed amount to the
     * two legs. They were floats, and a float reaching the ledger was bound
     * at `precision` 14, so an exchange of 1234567.12345678 was booked as
     * 1234567.1234568 on both sides.
     *
     * @return array{source_rate: mixed, destination_rate: mixed, amount: string, entered_base: string, fee: string, fee_mode: FeeMode, source_net: string, source_fee_in_wallet: string, source_payable: string, destination_net: string, tax: TaxQuote}
     */
    public function price(WalletModel $sourceWallet, WalletModel $destinationWallet, string|int|float $entered): array
    {
        $sourceRate      = Wallet::fxRate($sourceWallet);
        $destinationRate = Wallet::fxRate($destinationWallet);
        $feeMode         = FeeMode::exchange();

        $sourceCode = $sourceWallet->currency->code;

        /*
         * THE TYPED FIGURE IS CUT TO WHAT THE PAYING WALLET CAN STATE BEFORE
         * ANYTHING DERIVES FROM IT, and this is the whole of the fix.
         *
         * `source_payable` was quantized at the source currency while
         * `source_net` and `destination_net` were not, so the gap between the
         * typed figure and the payable one was money nobody paid. A typed
         * 1.005 into a two-decimal wallet debited 1.00 and credited
         * 1.00500000 - half a cent CREATED, repeatable as often as the
         * customer cared to retype it. The dust that was looked for here was
         * leaving; it was arriving.
         *
         * Cut rather than rounded. `Money::quantize` is half-up and would
         * debit 1.01 for a typed 1.005, charging more than the customer
         * asked; truncation can never charge more than was typed and can
         * never mint. It is also what `source_payable` already did, so the
         * three figures now agree instead of two of them disagreeing with
         * the third.
         *
         * Not a round-trip through the base currency: no rate is involved, so
         * the 0.99999998-for-a-typed-1 problem the comment below describes
         * cannot come back.
         */
        $entered = DecimalMoney::normalize($entered, Money::decimals($sourceCode));

        // The customer types in the currency they hold — 0.5 BTC, not the
        // 31,250 base units it happens to be worth. The base figure is
        // derived from it so the ledger, the FX snapshot and every consumer
        // already reading `amount` are untouched.
        $amount = Wallet::baseAmount($sourceWallet, $entered);
        $fee    = Wallet::calculateFeeByRole($sourceWallet, $amount, CurrencyRole::EXCHANGE);

        // The paying side is the authoritative one: it is what the customer
        // typed and what leaves their wallet, so it is used verbatim rather
        // than converted to base and back. Round-tripping it debited
        // 0.99999998 for a typed 1 — invisible at two decimals, but real
        // dust accumulating in a decimal(20,8) balance. The base figure
        // carries the rounding instead, which is where a derived reporting
        // number belongs.
        //
        // The fee is converted rather than typed, so it is quantized the
        // same way the entered amount already is: a percentage fee in a
        // two-decimal wallet is otherwise charged to eight places and
        // leaves the same dust behind by the back door.
        $sourceFeeInWallet = Money::quantize(DecimalMoney::multiply($fee, $sourceRate), $sourceCode);
        $enteredBase       = $amount;

        if ($feeMode->isDeducted()) {
            /*
             * Deducted: the typed figure is the whole debit, the fee is
             * charged on it, and what remains is what converts. The base
             * `amount` follows the net so the receiving side - and every
             * ledger reader of `amount` - sees the figure that was actually
             * exchanged. A net of nothing is refused by the caller, which
             * already turns away a figure too small to state.
             */
            $sourcePayableAmount = $entered;
            $sourceNetAmount     = DecimalMoney::subtract($sourcePayableAmount, $sourceFeeInWallet, Money::decimals($sourceCode));
            $amount              = DecimalMoney::compare($sourceNetAmount, '0') > 0 ? Wallet::baseAmount($sourceWallet, $sourceNetAmount) : '0';
        } else {
            $sourceNetAmount     = $entered;
            $sourcePayableAmount = DecimalMoney::add($sourceNetAmount, $sourceFeeInWallet, Money::decimals($sourceCode));
        }

        // The receiving side genuinely converts: exact decimal arithmetic,
        // because a satoshi-scale rate multiplied in floats loses digits at
        // exactly the scale that matters — then quantized to the receiving
        // currency, since this is the figure credited to that wallet.
        $destinationNetAmount = Money::quantize(
            DecimalMoney::multiply($amount, $destinationRate),
            $destinationWallet->currency->code,
        );

        $owner = $sourceWallet->loadMissing('user')->user;
        $tax   = $owner === null || DecimalMoney::compare($sourceNetAmount, '0') <= 0
            ? TaxQuote::none($sourceCode, Money::decimals($sourceCode))
            : $this->taxes->quote(
                TaxableType::Exchange,
                $owner,
                $sourceCode,
                DecimalMoney::normalize($sourceNetAmount),
                DecimalMoney::normalize($sourceFeeInWallet),
                (string) $sourceRate,
            );

        if (! $tax->isEmpty()) {
            $sourcePayableAmount = DecimalMoney::add($sourcePayableAmount, $tax->total, Money::decimals($sourceCode));
        }

        // Returned exactly as the arithmetic produced them, with no casting.
        // The rates are what TransactionData stores in a ?string column, and
        // casting them to float here is enough to break the ledger write -
        // which is how this was found.
        return [
            'source_rate'      => $sourceRate,
            'destination_rate' => $destinationRate,
            'amount'           => $amount,
            // The typed figure in base, which is what the role's limits are
            // checked against in both modes: a customer who meets the minimum
            // is not then told that the amount less the fee does not.
            'entered_base'         => $enteredBase,
            'fee'                  => $fee,
            'fee_mode'             => $feeMode,
            'source_net'           => $sourceNetAmount,
            'source_fee_in_wallet' => $sourceFeeInWallet,
            'source_payable'       => $sourcePayableAmount,
            'destination_net'      => $destinationNetAmount,
            'tax'                  => $tax,
        ];
    }

    /**
     * @param  array{source_wallet_id: string, destination_wallet_id: string, amount: mixed} $validated
     * @return ExchangeLegs
     *
     * @throws NotifyErrorException
     * @throws Throwable
     */
    public function exchange(User $user, array $validated): array
    {
        $entered = (string) $validated['amount'];

        return DB::transaction(function () use ($user, $validated, $entered): array {
            $wallets = WalletModel::query()
                ->where('user_id', $user->id)
                ->active(CurrencyRole::EXCHANGE)
                ->whereIn('uuid', [
                    $validated['source_wallet_id'],
                    $validated['destination_wallet_id'],
                ])
                ->with(['currency.roles'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $sourceWallet      = $wallets->get($validated['source_wallet_id']);
            $destinationWallet = $wallets->get($validated['destination_wallet_id']);

            if (! $sourceWallet || ! $destinationWallet) {
                throw new NotifyErrorException(__('The selected wallets are unavailable.'));
            }

            Wallet::assertOperational($sourceWallet, CurrencyRole::EXCHANGE);
            Wallet::assertOperational($destinationWallet, CurrencyRole::EXCHANGE);

            // Both sides are priced against the base currency, so an unusable
            // rate on either one makes the pair meaningless. This is the path
            // the "convert BTC to USD" report came through: with BTC left at
            // the placeholder rate of 1, the cross-rate silently became 1:1.
            Wallet::assertConvertible($sourceWallet->currency);
            Wallet::assertConvertible($destinationWallet->currency);

            // Both rates come from the same accessor `assertConvertible()` just
            // vetted. Reading the raw column here instead meant the guard and
            // the arithmetic consulted different numbers whenever live rates
            // were enabled, so a pair the guard had cleared could still be
            // priced at something else entirely.
            [
                'source_rate'          => $sourceRate,
                'destination_rate'     => $destinationRate,
                'amount'               => $amount,
                'entered_base'         => $enteredBase,
                'fee'                  => $fee,
                'source_net'           => $sourceNetAmount,
                'source_fee_in_wallet' => $sourceFeeInWallet,
                'source_payable'       => $sourcePayableAmount,
                'destination_net'      => $destinationNetAmount,
                'tax'                  => $tax,
            ] = $this->price($sourceWallet, $destinationWallet, $entered);

            $taxColumns = $tax->columns();

            /*
             * Refuse an amount either wallet cannot state.
             *
             * Both sides, because they fail differently. A figure below the
             * paying wallet's precision cuts to nothing and there is nothing
             * to debit; a figure large enough to state in the paying currency
             * can still convert to nothing in the receiving one, and that is
             * the worse of the two - money leaves and nothing arrives.
             *
             * Both were already refused, but by accident and only at the far
             * end: subtractMoney() and creditBalance() each reject a
             * non-positive figure with "Amount must be greater than zero",
             * which tells a customer who typed 0.001 nothing about why. Said
             * here, once, before any of it runs.
             *
             * The paying-side test is belt-and-braces and is known not to be
             * independently reachable: the destination figure is derived from
             * the quantized source figure, so a source of zero always gives a
             * destination of zero and the second test alone would catch it.
             * Removing it survives mutation for that reason, and it is kept
             * because the two failures are different things to a reader and
             * the day the destination stops deriving from the source is not a
             * day to discover this by arithmetic.
             */
            if (
                DecimalMoney::compare($sourceNetAmount, '0')         <= 0
                || DecimalMoney::compare($destinationNetAmount, '0') <= 0
            ) {
                throw new NotifyErrorException(__('That amount is too small to exchange between these wallets.'));
            }

            if (DecimalMoney::compare((string) $sourceWallet->balance, $sourcePayableAmount) < 0) {
                throw new NotifyErrorException(__('Insufficient balance.'));
            }

            // The role's limits apply to the figure the customer typed, in
            // both fee modes; `amount` is the net under Deducted.
            Wallet::validateAmountLimitByRole($sourceWallet, $enteredBase, CurrencyRole::EXCHANGE);

            // Create source transaction
            $sourceTransactionData = new TransactionData(
                user_id: $user->id,
                trx_type: TrxType::EXCHANGE_MONEY,
                amount: $amount,
                amount_flow: AmountFlow::MINUS,
                fee: $fee,
                currency: siteCurrency(),
                net_amount: $sourceNetAmount,
                payable_amount: $sourcePayableAmount,
                payable_currency: $sourceWallet->currency->code,
                wallet_reference: $sourceWallet->uuid,
                description: __('Exchanging money to :wallet Wallet', ['wallet' => $destinationWallet->name]),
                status: TrxStatus::COMPLETED,
                // FX snapshot (IAS 21). Each leg records the rate that priced
                // it, so the pair stays reconcilable after either rate moves —
                // an exchange is the one flow where both sides convert.
                exchange_rate: $sourceRate,
                base_amount: $amount,
                base_fee: $fee,
                base_currency: siteCurrency(),
                wallet_amount: $sourceNetAmount,
                wallet_fee: $sourceFeeInWallet,
                entered_amount: $entered,
                entered_currency: $sourceWallet->currency->code,
                // GF6: the tax inside payable_amount, apart from the fee, so
                // payable = net + wallet_fee + wallet_tax.
                wallet_tax: $taxColumns['wallet_tax'],
                base_tax: $taxColumns['base_tax'],
                tax_breakdown: $taxColumns['tax_breakdown'],
            );
            $sourceTrx = Transaction::create($sourceTransactionData);

            // Create destination transaction by copying and overriding properties
            $destinationTransactionData = $sourceTransactionData->copy([
                'amount_flow'      => AmountFlow::PLUS,
                'fee'              => 0, // No fee for destination wallet in exchange
                'net_amount'       => $destinationNetAmount,
                'payable_amount'   => $destinationNetAmount,
                'payable_currency' => $destinationWallet->currency->code,
                'wallet_reference' => $destinationWallet->uuid,
                'trx_reference'    => $sourceTrx->trx_id,
                'description'      => __('Exchanging money from :wallet Wallet', ['wallet' => $sourceWallet->name]),
                // The receiving leg is priced by the destination wallet's own
                // rate, and carries no fee.
                'exchange_rate' => $destinationRate,
                'base_fee'      => '0',
                'wallet_amount' => $destinationNetAmount,
                'wallet_fee'    => '0',
                // The customer typed a figure on the source side only. The
                // receiving leg's own-currency amount is `payable_amount`;
                // claiming it was entered would misreport what they chose.
                'entered_amount'   => null,
                'entered_currency' => null,
                // The tax was paid on the paying leg; the receiving one
                // carries none.
                'wallet_tax'    => null,
                'base_tax'      => null,
                'tax_breakdown' => null,
            ]);

            $destinationTrx = Transaction::create($destinationTransactionData);

            $destinationTrx->from_currency = $sourceWallet->currency->code;
            $destinationTrx->from_amount   = $sourcePayableAmount;

            // Trigger notifications & balance sync
            $this->handler->handleSuccess($sourceTrx);
            $this->handler->handleSuccess($destinationTrx);

            return ['source' => $sourceTrx, 'destination' => $destinationTrx];
        });
    }
}
