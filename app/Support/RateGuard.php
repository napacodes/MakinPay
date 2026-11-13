<?php

declare(strict_types=1);

namespace App\Support;

use App\Constants\CurrencyType;
use App\Models\Currency;
use Carbon\CarbonInterface;

/**
 * Decide whether a currency's exchange rate can be converted through.
 *
 * A conversion at an unusable rate does not fail — it succeeds, quietly, with
 * a wrong number. That is how this system offered `1000 USD = 1000 BTC`: BTC
 * shipped at the placeholder rate of exactly 1, nothing distinguished it from
 * a rate someone had actually set, and `$amount * $rate` asked no questions.
 *
 * Every payment system treats a missing or stale rate as a hard failure rather
 * than a silent identity conversion, because an unpriced trade is not a trade.
 * This class is that check.
 *
 * The rules are tiered by how certain they are:
 *
 *  - missing / zero / negative — unambiguous. A conversion is not merely
 *    wrong here, it is undefined. Always blocked.
 *  - stale — only meaningful where the install asked for live rates and the
 *    feed has stopped. A static-rate install has set its rates deliberately
 *    and must never be blocked for not changing them.
 *  - a non-stablecoin crypto at exactly 1 — a heuristic, and the one that
 *    catches the actual bug. USDT and USDC legitimately sit at 1 against a USD
 *    base, so a bare "crypto and rate = 1" rule would break correct configs;
 *    the pegged assets are excluded by name. Operators who genuinely need it
 *    can switch this rule off.
 *
 * The base currency is always exempt: its rate is 1 by definition.
 */
class RateGuard
{
    /**
     * Assets that are pegged, and therefore correct at a rate of 1 against a
     * USD base. Excluding these is what keeps the placeholder check from
     * condemning a valid stablecoin configuration.
     *
     * @var list<string>
     */
    public const PEGGED = ['USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'USDP', 'GUSD', 'FDUSD', 'PYUSD'];

    public const REASON_MISSING = 'missing';

    public const REASON_PLACEHOLDER = 'placeholder';

    public const REASON_STALE = 'stale';

    /**
     * Why this currency cannot be converted, or null when it can.
     */
    public function reject(?Currency $currency): ?string
    {
        if ($currency === null) {
            return self::REASON_MISSING;
        }

        // The *effective* rate — what a conversion will actually multiply by —
        // not the stored one. The two differ whenever live rates are enabled,
        // and guarding the stored value while the conversion used the live one
        // is what let the original bug through: BTC sat correctly at 0.000016
        // in the table, so the guard passed, while the accessor handed the
        // conversion a 1 and booked 1000 USD as 1000 BTC. One source, one
        // verdict. `exchange_rate` falls back to the stored rate whenever the
        // live lookup is unavailable, so a static install is unaffected.
        $rate = $currency->exchange_rate;

        if ($rate === null || ! is_numeric($rate)) {
            return self::REASON_MISSING;
        }

        // Normalize before comparing: a small rate comes back from SQLite in
        // exponent form ("9.4118E-6"), which is_numeric() accepts but bcmath
        // rejects outright. DecimalMoney::rate() expands it.
        $rate = DecimalMoney::rate($rate);

        if (bccomp($rate, '0', DecimalMoney::RATE_SCALE) <= 0) {
            return self::REASON_MISSING;
        }

        // The base currency is the unit everything else is quoted in.
        if ($currency->default) {
            return null;
        }

        if ($this->isPlaceholder($currency, $rate)) {
            return self::REASON_PLACEHOLDER;
        }

        if ($this->isStale($currency)) {
            return self::REASON_STALE;
        }

        return null;
    }

    public function passes(?Currency $currency): bool
    {
        return $this->reject($currency) === null;
    }

    /**
     * A human explanation, for the message a customer or operator actually
     * sees. Deliberately says the rate is unavailable rather than quoting the
     * bad number, which would only invite someone to trust it.
     */
    public function message(Currency $currency, string $reason): string
    {
        return match ($reason) {
            self::REASON_STALE => __('The :code exchange rate is out of date. Please try again shortly.', [
                'code' => $currency->code,
            ]),
            default => __('The :code exchange rate is not available, so this amount cannot be converted. Please contact support.', [
                'code' => $currency->code,
            ]),
        };
    }

    /**
     * An unpegged crypto sitting at exactly 1 was never priced: no market puts
     * a coin at parity with the base unit to eighteen decimal places.
     */
    private function isPlaceholder(Currency $currency, string $rate): bool
    {
        if (! setting('rate_guard_placeholder', true)) {
            return false;
        }

        if (($currency->type ?? null) !== CurrencyType::CRYPTO) {
            return false;
        }

        if (in_array(strtoupper((string) $currency->code), self::PEGGED, true)) {
            return false;
        }

        return bccomp($rate, '1', DecimalMoney::RATE_SCALE) === 0;
    }

    /**
     * Only an install that opted into live rates can have stale ones. A
     * manually set rate is as current as the operator intends it to be, and a
     * rate with no recorded age predates the provenance columns — neither is
     * evidence of a broken feed.
     */
    private function isStale(Currency $currency): bool
    {
        if (! $currency->rate_live) {
            return false;
        }

        $updatedAt = $currency->rate_updated_at;

        if (! $updatedAt instanceof CarbonInterface) {
            return false;
        }

        $maxAgeMinutes = (int) setting('rate_max_age_minutes', 1440);

        if ($maxAgeMinutes <= 0) {
            return false;
        }

        return $updatedAt->diffInMinutes(now()) > $maxAgeMinutes;
    }
}
