<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Support;

use App\Support\DecimalMoney;
use Throwable;

/**
 * Reads an amount a provider sent back into an exact decimal string, or
 * gives up.
 *
 * A provider may send "1800.0000", 1800, 1800.5 or 1.8E3. Each becomes a
 * DecimalMoney string at the calculation scale. A float that needs more than
 * fifteen significant digits to read back cannot be trusted to be the amount
 * the provider meant, so it is refused rather than guessed: the caller treats
 * null as an unclear answer and never settles on it.
 *
 * Exponent notation is expanded digit by digit rather than through a float,
 * so "1.5e-2" reads as 0.015 and not as the double just below it.
 *
 * The result is for comparison with DecimalMoney::compare only, never for
 * arithmetic.
 */
final class ProviderDecimal
{
    private const MAX_FLOAT_DIGITS = 15;

    private const MAX_EXPONENT = 30;

    public static function from(mixed $value): ?string
    {
        try {
            if (is_string($value)) {
                return self::fromString(trim($value));
            }

            if (is_int($value)) {
                return DecimalMoney::normalize($value);
            }

            if (is_float($value)) {
                return self::fromFloat($value);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function fromString(string $value): ?string
    {
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        if (stripos($value, 'e') !== false) {
            $expanded = self::expandExponent($value);

            return $expanded === null ? null : DecimalMoney::normalize($expanded);
        }

        return DecimalMoney::normalize($value);
    }

    private static function fromFloat(float $value): ?string
    {
        if (! is_finite($value)) {
            return null;
        }

        for ($precision = 1; $precision <= self::MAX_FLOAT_DIGITS; $precision++) {
            $candidate = sprintf('%.*G', $precision, $value);

            if ((float) $candidate === $value) {
                return self::fromString($candidate);
            }
        }

        return null;
    }

    /**
     * "1.5E-2" becomes "0.015", exactly. Null when the exponent is too large
     * to be an amount.
     */
    private static function expandExponent(string $value): ?string
    {
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?[eE]([+-]?\d+)$/', $value, $parts) !== 1) {
            return null;
        }

        $exponent = (int) $parts[4];

        if (abs($exponent) > self::MAX_EXPONENT || ($parts[2] === '' && ($parts[3] ?? '') === '')) {
            return null;
        }

        $digits = $parts[2].($parts[3] ?? '');
        $point  = strlen($parts[2]) + $exponent;

        if ($point <= 0) {
            $plain = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $plain = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $plain = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return ($parts[1] === '-' ? '-' : '').$plain;
    }
}
