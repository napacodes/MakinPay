<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\FeeBreakdown;

/**
 * The single implementation of transaction fee arithmetic.
 *
 * Every fee in the platform is now
 *
 *     fee = clamp(fixed + amount * percent / 100, min, max)
 *
 * with fixed-only expressed as percent = 0 and percent-only as fixed = 0.
 * There is no branch to get wrong, which is the point: the old either/or shape
 * spread the same `$type === 'fixed' ? ... : ...` ternary across seven call
 * sites, two of which had already drifted apart from the rest.
 *
 * This matches how every major processor prices — Stripe 2.9% + $0.30, PayPal
 * 3.49% + $0.49, Wise fixed + variable — and makes "1% but at least $0.50,
 * capped at $25" expressible, which no single-type scheme can represent.
 *
 * Backed by bcmath rather than float so a fixed fee of 0.0005 BTC survives.
 */
class FeeCalculator
{
    /**
     * @param string|int|float      $amount  Principal the fee applies to
     * @param string|int|float|null $fixed   Flat component, in the amount's own currency
     * @param string|int|float|null $percent Percentage component, e.g. 2.9 for 2.9%
     * @param string|int|float|null $min     Optional floor on the resulting fee
     * @param string|int|float|null $max     Optional ceiling on the resulting fee
     */
    /**
     * @param bool $capToPrincipal Whether the result may not exceed `$amount`.
     *                             True for anything charged *on* a principal;
     *                             false for a payout *derived from* one, where
     *                             exceeding it is the point — a staking plan
     *                             returning more than the stake is not an error.
     */
    public function calculate(
        string|int|float $amount,
        string|int|float|null $fixed = 0,
        string|int|float|null $percent = 0,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        int $scale = DecimalMoney::CALCULATION_SCALE,
        bool $capToPrincipal = true,
    ): FeeBreakdown {
        $amount  = $this->nonNegative($amount, $scale);
        $fixed   = $this->nonNegative($fixed ?? 0, $scale);
        $percent = $this->nonNegative($percent ?? 0, $scale);

        $percentAmount = DecimalMoney::percentage($amount, $percent, $scale);
        $total         = bcadd($fixed, $percentAmount, $scale);
        $clamped       = false;

        if ($min !== null && bccomp($total, $this->nonNegative($min, $scale), $scale) < 0) {
            $total   = $this->nonNegative($min, $scale);
            $clamped = true;
        }

        if ($max !== null && bccomp($total, $this->nonNegative($max, $scale), $scale) > 0) {
            $total   = $this->nonNegative($max, $scale);
            $clamped = true;
        }

        // A fee may never exceed the principal it is charged on. A payout
        // derived from one may — see $capToPrincipal.
        if ($capToPrincipal && bccomp($total, $amount, $scale) > 0) {
            $total   = $amount;
            $clamped = true;
        }

        return new FeeBreakdown(
            fixed: $fixed,
            percent: $percent,
            percentAmount: $percentAmount,
            total: $total,
            net: bcsub($amount, $total, $scale),
            clamped: $clamped,
        );
    }

    /**
     * Convenience for the many call sites that only need the number.
     */
    public function total(
        string|int|float $amount,
        string|int|float|null $fixed = 0,
        string|int|float|null $percent = 0,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        int $scale = DecimalMoney::CALCULATION_SCALE,
        bool $capToPrincipal = true,
    ): float {
        return $this->calculate($amount, $fixed, $percent, $min, $max, $scale, $capToPrincipal)->toFloat();
    }

    /**
     * Fee for an amount given in the base currency, where the fee's own
     * components are denominated in the account's currency.
     *
     * This is how the industry publishes fees: Stripe charges EEA cards
     * "1.5% + EUR 0.25" and US cards "2.9% + USD 0.30" — separate per-currency
     * figures, not one base figure converted at today's rate. Converting would
     * make the fixed component drift with the exchange rate daily, which no
     * processor does, and which no merchant could reconcile.
     *
     * So the arithmetic happens entirely in the account currency — including
     * the min/max clamp, which is only meaningful in the unit it was written
     * in — and only the result is expressed back in base for callers whose
     * contract is base-denominated.
     *
     * A rate of 1 (or none) collapses this to `total()` exactly.
     *
     * Returned as a decimal string at `$scale`, the exact figure, because this
     * is the fee the ledger stores: as a float it was bound at `precision` 14
     * on the way to the row (CR1).
     */
    public function totalFromBase(
        string|int|float $baseAmount,
        string|int|float|null $rate,
        string|int|float|null $fixed = 0,
        string|int|float|null $percent = 0,
        string|int|float|null $min = null,
        string|int|float|null $max = null,
        int $scale = DecimalMoney::CALCULATION_SCALE,
    ): string {
        $rate = DecimalMoney::rate($rate ?? 1);

        if (bccomp($rate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            $rate = '1';
        }

        $accountAmount = DecimalMoney::multiply((string) $baseAmount, $rate, $scale);
        $accountFee    = $this->calculate($accountAmount, $fixed, $percent, $min, $max, $scale)->total;

        // bcdiv truncates, so divide with headroom and round back to scale.
        return DecimalMoney::round(bcdiv((string) $accountFee, $rate, $scale + 2), $scale);
    }

    /**
     * Split a legacy single (type, amount) pair into the two-column shape.
     *
     * @return array{fixed: string, percent: string}
     */
    public static function fromLegacy(?string $type, string|int|float|null $value): array
    {
        $value = (string) ($value ?? 0);

        return $type === 'percent'
            ? ['fixed' => '0', 'percent' => $value]
            : ['fixed' => $value, 'percent' => '0'];
    }

    /**
     * Derive the legacy discriminator from the two-column shape, so
     * `fee_type`/`charge_type` stay valid for customer code and API consumers
     * that still read them.
     */
    public static function toLegacyType(string|int|float|null $fixed, string|int|float|null $percent): string
    {
        $hasFixed   = (float) ($fixed ?? 0)   > 0;
        $hasPercent = (float) ($percent ?? 0) > 0;

        return (! $hasFixed && $hasPercent) ? 'percent' : 'fixed';
    }

    private function nonNegative(string|int|float $value, int $scale): string
    {
        $normalized = DecimalMoney::normalize($value, $scale);

        return bccomp($normalized, '0', $scale) < 0
            ? DecimalMoney::normalize('0', $scale)
            : $normalized;
    }
}
