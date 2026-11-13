<?php

declare(strict_types=1);

namespace App\Services\Payment\Stripe;

/**
 * Stripe amount conversion. Most currencies are quoted in cents, but the
 * zero-decimal ones are quoted in whole units — sending cents for those
 * overcharges the buyer 100x, so the list lives in one place.
 */
class StripeMoney
{
    /**
     * @var array<int, string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif',
        'clp',
        'djf',
        'gnf',
        'jpy',
        'kmf',
        'krw',
        'mga',
        'pyg',
        'rwf',
        'ugx',
        'vnd',
        'vuv',
        'xaf',
        'xof',
        'xpf',
    ];

    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtolower(trim($currency)), self::ZERO_DECIMAL_CURRENCIES, true);
    }

    public static function toSubunit(float $amount, string $currency): int
    {
        return (int) round($amount * (self::isZeroDecimal($currency) ? 1 : 100));
    }

    public static function toMajor(int $subunit, string $currency): float
    {
        return self::isZeroDecimal($currency) ? (float) $subunit : $subunit / 100;
    }

    /**
     * Convert an exact decimal string to Stripe's integer amount with string
     * arithmetic only. Returns null for anything that is not a non-negative
     * decimal, or that would need rounding: "10.505" USD and "100.5" JPY are
     * refused rather than quietly paid as a different amount.
     */
    public static function toSubunitExact(string $amount, string $currency): ?int
    {
        if (preg_match('/\A(\d+)(?:\.(\d+))?\z/', trim($amount), $parts) !== 1) {
            return null;
        }

        $decimals = self::isZeroDecimal($currency) ? 0 : 2;
        $fraction = $parts[2] ?? '';

        if (rtrim(substr($fraction, $decimals), '0') !== '') {
            return null;
        }

        $digits = ltrim($parts[1].str_pad(substr($fraction, 0, $decimals), $decimals, '0'), '0');

        if (strlen($digits) > 18) {
            return null;
        }

        return (int) ($digits === '' ? '0' : $digits);
    }

    /**
     * The exact major-unit decimal string for a Stripe integer amount, so a
     * reported amount can be compared without passing through a float.
     */
    public static function toMajorExact(int $subunit, string $currency): string
    {
        if (self::isZeroDecimal($currency)) {
            return (string) $subunit;
        }

        $sign   = $subunit < 0 ? '-' : '';
        $digits = str_pad((string) abs($subunit), 3, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -2).'.'.substr($digits, -2);
    }
}
