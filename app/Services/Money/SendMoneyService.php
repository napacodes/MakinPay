<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Constants\CurrencyRole;
use App\Data\TransactionData;
use App\Enums\AmountFlow;
use App\Enums\Tax\TaxableType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Models\Transaction as TransactionModel;
use App\Models\User;
use App\Models\Wallet as WalletModel;
use App\Services\FeatureManager;
use App\Services\Handlers\SendMoneyHandler;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxQuote;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Transaction;
use Wallet;

/**
 * Sending money from one customer's wallet to another's.
 *
 * Lifted out of SendMoneyController so the app and the panel cannot end up
 * meaning different things by a transfer. The controller keeps the flash
 * message and the redirect; everything that decides what happens to the money
 * is here.
 *
 * THE FEE IS DERIVED HERE AND NEVER ACCEPTED FROM A CALLER. There is no
 * parameter to pass one in, and no branch that reads one from the input array,
 * because a caller that could name its own fee could name zero. The same is
 * true of the total and the net: all three come from the wallet's own currency
 * role, which is the administrator's setting.
 *
 * What this service does NOT do is check the balance before it starts. That
 * check exists in WalletService::subtractMoney as a single conditional UPDATE
 * - where balance >= amount - which is both the real guarantee and the reason
 * two simultaneous transfers from one wallet are safe. A read-then-write check
 * here would be decoration in front of it; the controller keeps one only to
 * produce a readable message before the attempt.
 */
final class SendMoneyService
{
    public function __construct(
        private readonly SendMoneyHandler $handler,
        private readonly FeatureManager $features,
        private readonly WalletPinGuard $pins,
        private readonly TaxEngine $taxes = new TaxEngine,
    ) {}

    /**
     * Everything a transfer resolves to, without performing it.
     *
     * Both the preview and the transfer itself call this, so a customer is
     * never shown one recipient or one fee and charged another. It refuses
     * what a preview must refuse - a wallet that cannot send, a recipient who
     * cannot be resolved, a transfer to yourself - and stops short of the two
     * checks that are about this moment rather than about the request: whether
     * the balance covers it, and whether the amount sits inside the window.
     *
     * THE FEE IS DERIVED HERE, exactly as before, and there is still no way to
     * pass one in.
     *
     * GF6: the tax is worked out here, on top of the priced transfer, from
     * the net and the fee in the sender's currency. The payable includes it -
     * it is what the wallet is debited - and the tax key is the TaxQuote, empty
     * when no rule applies, in which case every figure is what it was.
     * price() itself stays untaxed: mass payments price through it and are
     * not a taxable type.
     *
     * CR1: every figure is a decimal string. They were floats, and a float
     * reaching the ledger was bound at `precision` 14, so a transfer of
     * 1234567.12345678 was written as 1234567.1234568.
     *
     * @param  array{recipient: string, wallet_id: mixed, amount: mixed}                                                                                                                                              $validated
     * @return array{sender_wallet: WalletModel, recipient_wallet: WalletModel, entered: string, amount: string, fee: string, total: string, net: string, payable: string, payable_before_tax: string, tax: TaxQuote}
     *
     * @throws NotifyErrorException
     */
    public function quote(User $user, array $validated): array
    {
        $entered = (string) $validated['amount'];

        $senderWallet = $user->wallets()
            ->with(['currency.roles', 'user'])
            ->findOrFail($validated['wallet_id']);

        Wallet::assertOperational($senderWallet, CurrencyRole::SENDER);

        $recipientWallet = $this->resolveRecipient($user, (string) $validated['recipient'], $senderWallet);

        Wallet::assertOperational($recipientWallet);

        if (Wallet::isSelfTransaction($recipientWallet, $senderWallet)) {
            throw new NotifyErrorException(__('You cannot send money to yourself.'));
        }

        /*
         * The typed figure is CUT to what the sender's wallet can state
         * before anything derives from it, the same way and for the same
         * reason as ExchangeMoneyService.
         *
         * Without this the round trip through base rounded HALF-UP: a typed
         * 1.005 was debited as 1.01, charging more than the customer asked
         * for. Truncation can never do that. The two flows were settled the
         * same way deliberately - they were disagreeing, one charging 1.01
         * and the other 1.00 for the same typed figure, and which one a
         * customer met depended only on which screen they were on.
         *
         * Nothing here was UNSAFE before: both legs were written from the
         * same quantized net, so a transfer always conserved. This is about
         * charging what was typed.
         */
        $price = $this->price($senderWallet, $entered);
        $code  = (string) $senderWallet->currency->code;
        $scale = Money::decimals($code);

        $net     = DecimalMoney::normalize($price['net'], $scale);
        $payable = DecimalMoney::normalize($price['payable'], $scale);

        $tax = $this->taxes->quote(
            TaxableType::SendMoney,
            $user,
            $code,
            $net,
            DecimalMoney::subtract($payable, $net, $scale),
            Wallet::fxRate($senderWallet),
        );

        return [
            'sender_wallet'    => $senderWallet,
            'recipient_wallet' => $recipientWallet,
            ...$price,
            'payable'            => DecimalMoney::add($payable, $tax->total, $scale),
            'payable_before_tax' => $price['payable'],
            'tax'                => $tax,
        ];
    }

    /**
     * The figures one transfer of the typed amount resolves to from this
     * wallet: the typed figure cut to the wallet's precision, the base amount,
     * the sender fee from the currency role, and the wallet-side net and
     * payable.
     *
     * quote() prices through this, so anything else that prices a transfer
     * (mass payment rows) cannot disagree with it. As in quote(), the fee is
     * derived and never accepted.
     *
     * Every figure is a decimal string: the typed one at the wallet's
     * decimals, the base ones at calculation scale, the wallet-side ones at
     * the wallet's decimals. No float is made anywhere on the way (CR1).
     *
     * @return array{entered: string, amount: string, fee: string, total: string, net: string, payable: string}
     */
    public function price(WalletModel $sender, string|int|float $entered): array
    {
        $entered = DecimalMoney::normalize($entered, Money::decimals($sender->currency->code));

        // The customer types in the currency they hold. Converting to base
        // here, once, keeps `amount` base-denominated for the ledger, the
        // FX snapshot and every consumer already reading it.
        $amount = Wallet::baseAmount($sender, $entered);

        $fee         = Wallet::calculateFeeByRole($sender, $amount, CurrencyRole::SENDER);
        $totalAmount = DecimalMoney::add($amount, $fee, DecimalMoney::CALCULATION_SCALE);

        $netAmount     = Wallet::conversionAmount($sender, $amount);
        $payableAmount = Wallet::conversionAmount($sender, $totalAmount);

        // Returned as the arithmetic produced them, with no casting: these are
        // what TransactionData is handed, and a cast here is enough to break
        // the ledger write.
        return [
            'entered' => $entered,
            'amount'  => $amount,
            'fee'     => $fee,
            'total'   => $totalAmount,
            'net'     => $netAmount,
            'payable' => $payableAmount,
        ];
    }

    /**
     * @param  array{recipient: string, wallet_id: mixed, amount: mixed, note?: string|null} $validated
     * @return array{sender: TransactionModel, recipient: TransactionModel}
     *
     * @throws NotifyErrorException
     * @throws \Throwable
     */
    public function send(User $user, array $validated): array
    {
        /*
         * FIRST, before the recipient is resolved.
         *
         * Two reasons. A wrong PIN must not be usable to probe which
         * recipients exist - the lookup refuses an unknown one differently
         * from a known one - and this is a service that moves money, so it
         * does not rely on its caller having checked. The mobile controller
         * asserts as well, ahead of the extra quote() it makes for the
         * receipt; that is not redundant, it is that call being guarded too.
         *
         * The guard demands nothing from a customer who has not set a PIN, so
         * this does not change the flow for anyone who has not opted in.
         */
        $this->pins->assert($user, $validated['wallet_pin'] ?? null);

        $note = $validated['note'] ?? null;

        [
            'sender_wallet'    => $senderWallet,
            'recipient_wallet' => $recipientWallet,
            'entered'          => $entered,
            'amount'           => $amount,
            'fee'              => $fee,
            'net'              => $netAmount,
            'payable'          => $payableAmount,
            'tax'              => $tax,
        ] = $this->quote($user, $validated);

        if (! Wallet::isWalletBalanceSufficient($senderWallet->uuid, $payableAmount)) {
            throw new NotifyErrorException(__('Insufficient balance.'));
        }

        Wallet::validateAmountLimitByRole($senderWallet, $amount, CurrencyRole::SENDER);

        return DB::transaction(fn (): array => $this->writeLedger(
            $senderWallet,
            $recipientWallet,
            $amount,
            $entered,
            $fee,
            $netAmount,
            $payableAmount,
            $note,
            $tax,
        ));
    }

    /**
     * A send the scheduler makes under a standing mandate: the PIN was
     * entered once when the schedule was created, so it is not asked for
     * here. Everything else - the quote, the balance, the limits, the ledger
     * - is exactly the send above. Only the schedule runner calls this.
     *
     * @param  array{recipient: string, wallet_id: mixed, amount: mixed, note?: string|null} $validated
     * @return array{sender: TransactionModel, recipient: TransactionModel}
     *
     * @throws NotifyErrorException
     * @throws Throwable
     */
    public function sendUnderMandate(User $user, array $validated): array
    {
        $note = $validated['note'] ?? null;
        [
            'sender_wallet'    => $senderWallet,
            'recipient_wallet' => $recipientWallet,
            'entered'          => $entered,
            'amount'           => $amount,
            'fee'              => $fee,
            'net'              => $netAmount,
            'payable'          => $payableAmount,
            'tax'              => $tax,
        ] = $this->quote($user, $validated);
        if (! Wallet::isWalletBalanceSufficient($senderWallet->uuid, $payableAmount)) {
            throw new NotifyErrorException(__('Insufficient balance.'));
        }
        Wallet::validateAmountLimitByRole($senderWallet, $amount, CurrencyRole::SENDER);

        return DB::transaction(fn (): array => $this->writeLedger($senderWallet, $recipientWallet, $amount, $entered, $fee, $netAmount, $payableAmount, $note, $tax));
    }

    /**
     * Resolve the recipient against the identifier methods the administrator
     * has enabled for this panel, not against every identifier the platform
     * understands.
     *
     * @throws NotifyErrorException
     */
    private function resolveRecipient(User $user, string $recipient, WalletModel $senderWallet): WalletModel
    {
        $accessRule = $this->features->find('send_money')
            ?->accessRules
            ->firstWhere('panel', $this->features->currentPanel($user));

        $lookup = Wallet::resolveRecipientWallet($recipient, $senderWallet->currency_id, $accessRule);

        if ($lookup['error'] !== null) {
            throw new NotifyErrorException($lookup['error']);
        }

        return $lookup['wallet'];
    }

    /**
     * Both legs, and the reference each holds to the other.
     *
     * @return array{sender: TransactionModel, recipient: TransactionModel}
     */
    private function writeLedger(
        WalletModel $senderWallet,
        WalletModel $recipientWallet,
        string $amount,
        string $entered,
        string $fee,
        string $netAmount,
        string $payableAmount,
        ?string $note,
        ?TaxQuote $tax = null,
    ): array {
        $decimals = Money::decimals($senderWallet->currency->code);
        $taxTotal = $tax?->total     ?? '0';
        $taxCols  = $tax?->columns() ?? ['wallet_tax' => null, 'base_tax' => null, 'tax_breakdown' => null];

        $senderData = new TransactionData(
            user_id: $senderWallet->user_id,
            trx_type: TrxType::SEND_MONEY,
            amount: $amount,
            amount_flow: AmountFlow::MINUS,
            fee: $fee,
            currency: siteCurrency(),
            net_amount: $netAmount,
            payable_amount: $payableAmount,
            payable_currency: $senderWallet->currency->code,
            wallet_reference: $senderWallet->uuid,
            description: __('Sending money to :recipient', ['recipient' => $recipientWallet->user->name]),
            status: TrxStatus::COMPLETED,
            // The rate that produced net_amount and payable_amount.
            exchange_rate: Wallet::fxRate($senderWallet),
            /*
             * The wallet side is HANDED OVER, not left to be derived, and
             * that is the whole of this fix.
             *
             * completeFxSnapshot() fills wallet_fee from
             * quantize(base_fee x rate) when a writer omits it. The fee this
             * transfer actually retains is payableAmount - netAmount, each
             * already quantized at the wallet - and deriving then rounding
             * does not commute with rounding then differencing. On a typed
             * 1.005 the derived figure was 1.01 against a retained 1.00, so
             * net_amount + wallet_fee came to 2.02 while the wallet and
             * payable_amount both said 2.01.
             *
             * Transaction's own docblock points reconciliation at these
             * columns, so a penny that exists only in them is exactly the
             * kind of thing they are there to prevent. The exchange and
             * PaymentService already supply theirs; this is the odd one out.
             */
            wallet_amount: $netAmount,
            wallet_fee: DecimalMoney::subtract(DecimalMoney::subtract($payableAmount, $netAmount, $decimals), $taxTotal, $decimals),
            entered_amount: $entered,
            entered_currency: $senderWallet->currency->code,
            // GF6: the tax is inside payable_amount and kept apart from the
            // fee, so payable = net + wallet_fee + wallet_tax.
            wallet_tax: $taxCols['wallet_tax'],
            base_tax: $taxCols['base_tax'],
            tax_breakdown: $taxCols['tax_breakdown'],
        );
        $senderTrx = Transaction::create($senderData);

        // Cast explicitly. The controller this came from had no
        // declare(strict_types=1), so PHP coerced the int silently; this
        // file does, so the coercion has to be written down. The stored
        // value is unchanged either way.
        $recipientData = $this->recipientLegData($senderWallet, $recipientWallet, $amount, $netAmount, $note, (string) $senderTrx->id);
        $recipientTrx  = Transaction::create($recipientData);

        $senderTrx->update(['trx_reference' => $recipientTrx->id]);

        $senderTrx->ref_user_id    = $recipientWallet->user_id;
        $recipientTrx->ref_user_id = $senderWallet->user_id;

        $this->handler->handleSuccess($senderTrx);
        $this->handler->handleSuccess($recipientTrx);

        return ['sender' => $senderTrx, 'recipient' => $recipientTrx];
    }

    /**
     * The credit leg of a transfer: what the recipient's RECEIVE_MONEY row
     * says. writeLedger() and mass payment both build it here, so a credit
     * reads the same whichever of them wrote it.
     *
     * $reference is the debit leg's id, which is how CounterpartyResolver
     * finds the sender. $exchangeRate defaults to the sender wallet's rate,
     * the rate net_amount was converted through; a caller that froze its own
     * rate passes that instead.
     */
    public function recipientLegData(
        WalletModel $sender,
        WalletModel $recipient,
        string|int|float $amount,
        string|int|float $net,
        ?string $note,
        string $reference,
        string|float|null $exchangeRate = null,
    ): TransactionData {
        return new TransactionData(
            user_id: $recipient->user_id,
            trx_type: TrxType::RECEIVE_MONEY,
            amount: $amount,
            amount_flow: AmountFlow::PLUS,
            fee: 0,
            currency: siteCurrency(),
            net_amount: $net,
            payable_amount: $net,
            payable_currency: $recipient->currency->code,
            wallet_reference: $recipient->uuid,
            trx_reference: $reference,
            remarks: $note,
            description: __('Received money from :sender', ['sender' => $sender->user->name]),
            status: TrxStatus::COMPLETED,
            // The recipient's leg is credited through the sender's rate,
            // matching the net_amount above.
            exchange_rate: $exchangeRate === null ? Wallet::fxRate($sender) : DecimalMoney::rate($exchangeRate),
            base_fee: '0',
        );
    }
}
