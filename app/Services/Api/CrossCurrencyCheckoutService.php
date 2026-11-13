<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Constants\CurrencyRole;
use App\Exceptions\NotifyErrorException;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Money\ExchangeMoneyService;
use App\Services\Money\MerchantWalletPaymentService;
use App\Services\Tax\TaxPresenter;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Paying a merchant's checkout from a wallet in another currency.
 *
 * The customer at the counter holds USD; the checkout is in AED. Rather
 * than turning them away, the amount is exchanged into an AED wallet of
 * theirs - opened for them if they had none - and the checkout is paid from
 * that, as two ordinary ledger movements in one database transaction. The
 * exchange is priced by the same ExchangeMoneyService the exchange screen
 * uses, so the fee is the platform's exchange fee and nothing bespoke.
 */
class CrossCurrencyCheckoutService
{
    public function __construct(
        private readonly ExchangeMoneyService $exchange,
        private readonly MerchantWalletPaymentService $payments,
        private readonly WalletService $wallets,
    ) {}

    /**
     * How much of [$source] the checkout would take, or null when that
     * wallet cannot be priced into the checkout's currency.
     *
     * @return array{wallet_id: string, currency: string, amount: string, fee: string, tax: array{amount: string, lines: list<array{name: string, amount: string}>}, total: string, rate: string, receives: string, covers: bool}|null
     */
    public function quote(Wallet $source, Transaction $checkout): ?array
    {
        $target  = $this->payments->currencyFor($checkout);
        $entered = $this->sourceAmountFor($source, $target, (string) $checkout->payable_amount);

        if ($entered === null) {
            return null;
        }

        $priced = $this->price($source, $target, $entered);
        if ($priced === null) {
            return null;
        }

        $code  = (string) $source->currency->code;
        $total = ApiMoney::amount($priced['source_payable'], $code);

        return [
            'wallet_id' => $source->uuid,
            'currency'  => $code,
            'amount'    => ApiMoney::amount($priced['source_net'], $code),
            'fee'       => ApiMoney::amount($priced['source_fee_in_wallet'], $code),
            'tax'       => TaxPresenter::api($priced['tax']),
            'total'     => $total,
            'rate'      => DecimalMoney::rate(DecimalMoney::divide((string) $priced['destination_rate'], (string) $priced['source_rate'])),
            'receives'  => ApiMoney::amount($priced['destination_net'], $target),
            'covers'    => DecimalMoney::compare((string) $source->balance, $total) >= 0,
        ];
    }

    /**
     * Exchange, then pay, atomically. The paid checkout row comes back with
     * the exchange's paying leg beside it.
     *
     * @return array{settled: Transaction, exchange: Transaction}
     */
    public function pay(User $payer, Wallet $source, Transaction $checkout, Merchant $merchant): array
    {
        $targetCode = $this->payments->currencyFor($checkout);
        $entered    = $this->sourceAmountFor($source, $targetCode, (string) $checkout->payable_amount);

        if ($entered === null) {
            throw new NotifyErrorException(__('That wallet cannot be exchanged into :currency.', ['currency' => $targetCode]));
        }

        return DB::transaction(function () use ($payer, $source, $checkout, $merchant, $targetCode, $entered): array {
            $target = $this->wallets->getWalletByUserId((int) $payer->id, $targetCode);

            if ($target === null) {
                $currency = Currency::query()->where('code', $targetCode)->firstOrFail();
                $this->wallets->assertWithinWalletLimit($payer);
                $target = $this->wallets->createWalletForCurrency($payer, (int) $currency->id);
            }

            if ($target === null) {
                throw new NotifyErrorException(__('A :currency wallet could not be opened for you.', ['currency' => $targetCode]));
            }

            $legs = $this->exchange->exchange($payer, [
                'source_wallet_id'      => $source->uuid,
                'destination_wallet_id' => $target->uuid,
                'amount'                => $entered,
            ]);

            $settled = $this->payments->pay($payer, $target->fresh(['currency']), $checkout, $merchant);

            return ['settled' => $settled, 'exchange' => $legs['source']];
        });
    }

    /**
     * The caller's wallet in another currency that could cover the checkout
     * - the one with the most in it that does, or null.
     *
     * @return array<string, mixed>|null
     */
    public function suggest(User $payer, Transaction $checkout): ?array
    {
        $targetCode = $this->payments->currencyFor($checkout);

        $wallets = Wallet::query()
            ->where('user_id', $payer->id)
            ->active(CurrencyRole::EXCHANGE)
            ->with('currency.roles')
            ->get()
            ->filter(fn (Wallet $w) => $w->currency?->code !== $targetCode)
            ->sortByDesc(fn (Wallet $w) => (float) $w->balance);

        foreach ($wallets as $wallet) {
            $quote = $this->quote($wallet, $checkout);
            if ($quote !== null && $quote['covers']) {
                return $quote;
            }
        }

        return null;
    }

    /**
     * The smallest source amount whose exchange lands at least the payable
     * figure - priced by the real service and nudged up by one unit of the
     * source precision until it covers, so a rounding of the destination
     * never leaves the checkout a cent short.
     */
    private function sourceAmountFor(Wallet $source, string $targetCode, string $payable): ?string
    {
        $sourceCode = $source->currency?->code;
        if ($sourceCode === null || $sourceCode === $targetCode) {
            return null;
        }

        try {
            $sourceRate = $this->wallets->fxRate($source);
            $targetRate = $this->wallets->fxRate($targetCode);
        } catch (Throwable) {
            return null;
        }

        if (bccomp($sourceRate, '0', DecimalMoney::RATE_SCALE) <= 0 || bccomp($targetRate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return null;
        }

        $decimals = Money::decimals($sourceCode);
        $step     = $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1';
        $entered  = DecimalMoney::normalize(DecimalMoney::divide(DecimalMoney::multiply($payable, $sourceRate), $targetRate), $decimals);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $priced = $this->price($source, $targetCode, $entered);
            if ($priced === null) {
                return null;
            }
            if (DecimalMoney::compare((string) $priced['destination_net'], $payable) >= 0) {
                return $entered;
            }
            $entered = DecimalMoney::add($entered, $step, $decimals);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function price(Wallet $source, string $targetCode, string $entered): ?array
    {
        $target = Wallet::query()->where('user_id', $source->user_id)->whereHas('currency', fn ($q) => $q->where('code', $targetCode))->with('currency.roles')->first();

        if ($target === null) {
            // Not opened yet: price against a stand-in wallet in that currency.
            $currency = Currency::query()->where('code', $targetCode)->with('roles')->first();
            if ($currency === null) {
                return null;
            }
            $target = new Wallet(['user_id' => $source->user_id, 'currency_id' => $currency->id]);
            $target->setRelation('currency', $currency);
        }

        try {
            return $this->exchange->price($source->loadMissing('currency.roles'), $target, $entered);
        } catch (Throwable) {
            return null;
        }
    }
}
