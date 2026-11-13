<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Enums\Tax\TaxableType;
use App\Exceptions\NotifyErrorException;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\Wallet;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxQuote;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Prices a bill payment. Pure: it reads the biller, the item and the wallet
 * and writes nothing, so the quote endpoint and submit() share one answer.
 *
 * Every figure is an exact decimal string, never a float, at the biller
 * currency's own precision. That precision is passed to every add and
 * subtract explicitly, because DecimalMoney defaults to two places: a 3-dp
 * KWD total of 10.378 would otherwise come out as 10.37, and an 8-dp BTC
 * total of 0.01015100 as 0.01. The fee is the only figure that is rounded
 * (half-up, at the currency's precision); an amount the customer typed with
 * more decimals than the currency holds is refused, never rounded.
 *
 * In GF2 the wallet pays in the biller's own currency, so the wallet side of
 * the quote equals the bill side and no rate is involved.
 *
 * GF6: the wallet owner's bill payment tax rules are read too, and their tax
 * is added on top - `total` = amount + fee + tax - and carried as `tax`. The
 * tax is cut, never rounded, like every other GF6 tax; only a fixed tax part
 * goes through a rate, the wallet's, from the site currency.
 */
class BillPaymentPricer
{
    public function __construct(
        private readonly FeeCalculator $fees,
        private readonly TaxEngine $taxes = new TaxEngine,
        private readonly WalletService $wallets = new WalletService,
    ) {}

    /**
     * @throws NotifyErrorException when the biller's currency or package price cannot be priced
     * @throws ValidationException  on `wallet_id` (wrong currency), `biller_item_id` (not this biller's package) or `amount` (missing, invalid, out of range, too precise, or not the package price)
     */
    public function quote(Biller $biller, Wallet $wallet, ?BillerItem $item, ?string $amount): BillPaymentQuote
    {
        // BP1: a provider the breaker paused takes no new payments; its
        // billers stay listed and say so, rather than vanishing.
        if ($biller->provider?->isPaused() === true) {
            throw new NotifyErrorException(__('Payments to :biller are temporarily unavailable. Please try again later.', ['biller' => (string) $biller->name]));
        }

        $currency = strtoupper(trim((string) $biller->currency));
        $decimals = Money::decimalsIfKnown($currency);

        if ($decimals === null) {
            Log::warning('Bill payment biller currency is not a known currency', ['biller_id' => $biller->id]);

            throw new NotifyErrorException(__('This biller cannot be paid right now.'));
        }

        $walletCurrency = strtoupper(trim((string) $wallet->currency?->code));

        if ($walletCurrency !== $currency) {
            throw ValidationException::withMessages([
                'wallet_id' => __('Pay this biller from a :currency wallet.', ['currency' => $currency]),
            ]);
        }

        $amount = blank($amount) ? null : trim((string) $amount);

        $billAmount = $biller->amount_type === BillerAmountType::Fixed
            ? $this->fixedAmount($biller, $item, $amount, $decimals)
            : $this->openAmount($biller, $amount, $currency, $decimals);

        [$fixed, $percent] = $this->feeRule($biller, $currency);

        $breakdown = $this->fees->calculate($billAmount, $fixed, $percent, scale: self::feeScale($decimals));
        $fee       = DecimalMoney::round($breakdown->total, $decimals);

        // GF6: the payer's bill payment tax, on top of the bill and the fee.
        $owner = $wallet->loadMissing('user')->user;
        $tax   = $owner === null
            ? TaxQuote::none($walletCurrency, $decimals)
            : $this->taxes->quote(TaxableType::BillPayment, $owner, $walletCurrency, $billAmount, $fee, $this->wallets->fxRate($wallet));

        $total = DecimalMoney::add(DecimalMoney::add($billAmount, $fee, $decimals), $tax->total, $decimals);

        $rawBalance = (string) ($wallet->balance ?? '0');
        // Cut, not rounded: a balance is never shown as more than the wallet holds.
        $balance = DecimalMoney::normalize($rawBalance, $decimals);

        return new BillPaymentQuote(
            billAmount: $billAmount,
            billCurrency: $currency,
            amount: $billAmount,
            fee: $fee,
            total: $total,
            currency: $walletCurrency,
            decimals: $decimals,
            feeFixed: DecimalMoney::normalize($breakdown->fixed),
            feePercent: DecimalMoney::normalize($breakdown->percent),
            feeDescription: Money::describeFee($fixed, $percent, $currency),
            balance: $balance,
            balanceAfter: DecimalMoney::subtract($balance, $total, $decimals),
            sufficientBalance: DecimalMoney::compare($rawBalance, $total) >= 0,
            tax: $tax,
        );
    }

    /**
     * The scale the fee is worked out at before it is rounded to the
     * currency's precision. Half-up rounding at `$decimals` needs the digit
     * after it, and bcmath truncates, so a fee worked out at only the
     * currency's eight places would round an 8-dp fee down. Never below the
     * calculation scale, so a percent rate such as 1.555 is not cut short on
     * a 0-dp currency.
     */
    private static function feeScale(int $decimals): int
    {
        return max(DecimalMoney::CALCULATION_SCALE, $decimals) + 2;
    }

    /**
     * A package biller charges the item's price. A client amount, when one is
     * sent, must be that exact price.
     */
    private function fixedAmount(Biller $biller, ?BillerItem $item, ?string $amount, int $decimals): string
    {
        if ($item === null || (int) $item->biller_id !== (int) $biller->id) {
            throw ValidationException::withMessages(['biller_item_id' => __('Choose one of the packages this biller offers.')]);
        }

        $price = (string) $item->amount;

        // The currency's precision was lowered after the price was saved, so
        // the price can no longer be charged exactly. Refuse rather than round.
        if (DecimalMoney::compare(DecimalMoney::normalize($price, $decimals), $price) !== 0
            || DecimalMoney::compare($price, '0') <= 0) {
            Log::warning('Bill payment package price does not fit its currency', [
                'biller_id'      => $biller->id,
                'biller_item_id' => $item->id,
            ]);

            throw new NotifyErrorException(__('This package cannot be paid right now.'));
        }

        if ($amount !== null && (! $this->isPlainDecimal($amount) || DecimalMoney::compare($amount, $price) !== 0)) {
            throw ValidationException::withMessages([
                'amount' => __('This package costs :amount.', ['amount' => Money::format($price, (string) $biller->currency)]),
            ]);
        }

        return DecimalMoney::normalize($price, $decimals);
    }

    /**
     * An open biller charges what the customer typed, inside the biller's
     * inclusive min and max (a null bound is no bound).
     */
    private function openAmount(Biller $biller, ?string $amount, string $currency, int $decimals): string
    {
        if ($amount === null) {
            throw ValidationException::withMessages(['amount' => __('Enter the amount.')]);
        }

        if (! $this->isPlainDecimal($amount)) {
            throw ValidationException::withMessages(['amount' => __('Enter a valid amount.')]);
        }

        if (DecimalMoney::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount greater than zero.')]);
        }

        if ($this->fractionDigits($amount) > $decimals) {
            throw ValidationException::withMessages([
                'amount' => $decimals === 0
                    ? __(':currency amounts cannot have decimals.', ['currency' => $currency])
                    : __(':currency amounts can have at most :decimals decimal places.', ['currency' => $currency, 'decimals' => $decimals]),
            ]);
        }

        if ($biller->min_amount !== null && DecimalMoney::compare($amount, (string) $biller->min_amount) < 0) {
            throw ValidationException::withMessages([
                'amount' => __('The minimum amount is :amount.', ['amount' => Money::format((string) $biller->min_amount, $currency)]),
            ]);
        }

        if ($biller->max_amount !== null && DecimalMoney::compare($amount, (string) $biller->max_amount) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('The maximum amount is :amount.', ['amount' => Money::format((string) $biller->max_amount, $currency)]),
            ]);
        }

        return DecimalMoney::normalize($amount, $decimals);
    }

    /**
     * The biller's own fee overrides, each falling back to the provider's.
     *
     * A provider's fixed fee is written in one currency (`fee_currency`) and
     * applies only to billers priced in it. It is never converted at today's
     * rate, so for any other biller the fixed part is zero.
     *
     * Public so the admin biller list shows the same effective fee a quote
     * charges, instead of restating the inheritance rule.
     *
     * @return array{0: string, 1: string} [fixed, percent]
     */
    public function feeRule(Biller $biller, string $currency): array
    {
        $provider = $biller->provider;
        $percent  = (string) ($biller->fee_percent ?? $provider?->fee_percent ?? '0');

        if ($biller->fee_fixed !== null) {
            return [(string) $biller->fee_fixed, $percent];
        }

        $feeCurrency = trim((string) $provider?->fee_currency);

        if ($feeCurrency === '' || strcasecmp($feeCurrency, $currency) !== 0) {
            return ['0', $percent];
        }

        return [(string) ($provider->fee_fixed ?? '0'), $percent];
    }

    /**
     * A plain decimal bcmath can read exactly: digits with an optional sign
     * and point, and no exponent (DecimalMoney would expand "1e3" through a
     * float).
     */
    private function isPlainDecimal(string $value): bool
    {
        return preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $value) === 1;
    }

    /**
     * Decimal places that carry a value: "10.50" has one, "10.000" none.
     *
     * Counted on the string, not compared through DecimalMoney, whose
     * comparisons stop at eight places and would let a ninth digit through.
     */
    private function fractionDigits(string $value): int
    {
        $point = strpos($value, '.');

        return $point === false ? 0 : strlen(rtrim(substr($value, $point + 1), '0'));
    }
}
