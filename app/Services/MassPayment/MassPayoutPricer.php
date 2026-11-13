<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\FeeMode;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Models\User;
use App\Models\WithdrawMethod;
use App\Services\Payment\Stripe\StripeMoney;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use App\Support\Money;

/**
 * Prices one mass payout line exactly, for the batch owner, in decimal
 * strings only.
 *
 * No float is taken or returned, and nothing here reads `auth()`: the owner's
 * charge is pinned through {@see WithdrawMethod::chargeComponentsFor()}, so
 * the line is priced the same whether a merchant, an admin or nobody is
 * signed in. That is also why `PaymentService` is not reused - it prices for
 * the signed-in user.
 *
 * The typed figure E is in the method's currency, which equals the wallet's:
 *
 *   fee F   FeeCalculator on E with the owner's components, CUT (never
 *           rounded) to the currency's places, as a withdrawal cuts it.
 *   OnTop   net = E,      payable = E + F
 *   Deduct  net = E - F,  payable = E     (net <= 0 is fee_exceeds_amount)
 *   base    base_amount = net / r, base_fee = F / r at the calculation scale,
 *           with r the method rate, which is returned as `exchange_rate` so
 *           the ledger's FX snapshot and refund agree with these figures.
 *
 * so payable - net = wallet_fee = F always holds.
 *
 * Limits mirror a single withdrawal: the platform's min/max (base currency)
 * apply to the typed figure in both fee modes, exactly as
 * {@see WithdrawMethod::isWithinLimits()} applies them - no minimum is no
 * floor, and no maximum refuses every positive amount, because that is what
 * comparing against null does there. The processor's own limits apply to the
 * net, the figure it is asked to pay. `CurrencyRole::WITHDRAW` limits are not
 * enforced, for parity with withdrawals.
 *
 * Issue order: precision_unsupported, invalid_amount / too_many_decimals,
 * method_unavailable (no usable rate), fee_exceeds_amount,
 * amount_not_payable, then the limits.
 */
class MassPayoutPricer
{
    /**
     * The widest integer part a figure may have and still fit the
     * decimal(24,8) columns it is stored in, as GF4 guards it.
     */
    public const MAX_STORED_INTEGER_DIGITS = 16;

    private const AMOUNT_PATTERN = '/\A\d+(?:\.(\d+))?\z/';

    public function __construct(private readonly FeeCalculator $fees) {}

    /**
     * Price one typed amount through this method for this owner. The fee mode
     * is the withdraw fee mode unless a snapshot is handed in.
     */
    public function price(User $owner, WithdrawMethod $method, string $entered, ?FeeMode $feeMode = null): MassPayoutQuote
    {
        $feeMode ??= FeeMode::withdraw();
        $currency = strtoupper(trim((string) $method->currency));
        $decimals = Money::decimalsIfKnown($currency);

        if ($decimals === null) {
            return MassPayoutQuote::refused(MassPaymentIssue::PrecisionUnsupported);
        }

        $amountIssue = $this->amountIssue(trim($entered), $decimals);

        if ($amountIssue !== null) {
            return MassPayoutQuote::refused($amountIssue);
        }

        $rate = $this->rate($method);

        if ($rate === null) {
            return MassPayoutQuote::refused(MassPaymentIssue::MethodUnavailable);
        }

        $amount  = DecimalMoney::normalize(trim($entered), $decimals);
        $charges = $method->chargeComponentsFor($owner);

        $fee = DecimalMoney::normalize(
            $this->fees->calculate($amount, $charges['fixed'], $charges['percent'], $charges['min'], $charges['max'])->total,
            $decimals,
        );

        if ($feeMode->isDeducted()) {
            $payable = $amount;
            $net     = DecimalMoney::subtract($payable, $fee, $decimals);

            if (DecimalMoney::compare($net, '0') <= 0) {
                return MassPayoutQuote::refused(MassPaymentIssue::FeeExceedsAmount);
            }
        } else {
            $net     = $amount;
            $payable = DecimalMoney::add($net, $fee, $decimals);
        }

        $walletFee = DecimalMoney::subtract($payable, $net, $decimals);

        if ($method->mass_payout_driver === 'stripe_connect' && StripeMoney::toSubunitExact($net, $currency) === null) {
            return MassPayoutQuote::refused(MassPaymentIssue::AmountNotPayable);
        }

        $figures = [
            'entered_amount' => $amount,
            'base_amount'    => DecimalMoney::divide($net, $rate, DecimalMoney::CALCULATION_SCALE),
            'base_fee'       => DecimalMoney::divide($walletFee, $rate, DecimalMoney::CALCULATION_SCALE),
            'net_amount'     => $net,
            'payable_amount' => $payable,
            'wallet_fee'     => $walletFee,
            'exchange_rate'  => $rate,
        ];

        $limitIssue = $this->limitIssue($method, $figures, DecimalMoney::divide($amount, $rate, DecimalMoney::CALCULATION_SCALE));

        return $limitIssue === null ? MassPayoutQuote::priced($figures) : MassPayoutQuote::refused($limitIssue);
    }

    /**
     * A plain positive decimal with no more places than the currency holds.
     * Places are counted as typed, so "10.500" in a two-place currency is
     * too_many_decimals, as the wallet mass payment rule counts them.
     */
    private function amountIssue(string $entered, int $decimals): ?MassPaymentIssue
    {
        if (preg_match(self::AMOUNT_PATTERN, $entered, $parts) !== 1) {
            return MassPaymentIssue::InvalidAmount;
        }

        if (strlen($parts[1] ?? '') > $decimals) {
            return MassPaymentIssue::TooManyDecimals;
        }

        if ($this->integerDigits($entered) > self::MAX_STORED_INTEGER_DIGITS) {
            return MassPaymentIssue::InvalidAmount;
        }

        return DecimalMoney::compare($entered, '0') > 0 ? null : MassPaymentIssue::InvalidAmount;
    }

    /** The method rate as a decimal string, or null when it cannot convert. */
    private function rate(WithdrawMethod $method): ?string
    {
        $raw = $method->conversion_rate;

        if (! is_numeric($raw)) {
            return null;
        }

        $rate = DecimalMoney::rate($raw);

        return DecimalMoney::compare($rate, '0', DecimalMoney::RATE_SCALE) > 0 ? $rate : null;
    }

    /**
     * A figure too wide to store, then the platform limits on the typed
     * figure in base currency, then the processor limits on the net.
     *
     * @param array<string, string> $figures
     */
    private function limitIssue(WithdrawMethod $method, array $figures, string $typedBase): ?MassPaymentIssue
    {
        foreach (['entered_amount', 'base_amount', 'base_fee', 'net_amount', 'payable_amount', 'wallet_fee'] as $key) {
            if ($this->integerDigits($figures[$key]) > self::MAX_STORED_INTEGER_DIGITS) {
                return MassPaymentIssue::AboveLimit;
            }
        }

        $attributes = $method->getAttributes();
        $min        = $attributes['min_withdraw'] ?? null;
        $max        = $attributes['max_withdraw'] ?? null;

        if (is_numeric($min) && DecimalMoney::compare($typedBase, $min) < 0) {
            return MassPaymentIssue::BelowLimit;
        }

        if (! is_numeric($max) || DecimalMoney::compare($typedBase, $max) > 0) {
            return MassPaymentIssue::AboveLimit;
        }

        $providerMin = $method->provider_min_amount;
        $providerMax = $method->provider_max_amount;

        if (is_numeric($providerMin) && DecimalMoney::compare($figures['net_amount'], $providerMin) < 0) {
            return MassPaymentIssue::BelowProviderLimit;
        }

        if (is_numeric($providerMax) && DecimalMoney::compare($figures['net_amount'], $providerMax) > 0) {
            return MassPaymentIssue::AboveProviderLimit;
        }

        return null;
    }

    private function integerDigits(string $value): int
    {
        return strlen(ltrim(explode('.', ltrim($value, '-+'))[0], '0'));
    }
}
