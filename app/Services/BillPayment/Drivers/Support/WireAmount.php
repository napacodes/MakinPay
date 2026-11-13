<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers\Support;

use App\Support\DecimalMoney;
use JsonException;

/**
 * Turns a stored decimal amount into the JSON number a provider expects,
 * losslessly or not at all, and encodes the body that carries it.
 *
 * Provider APIs take amounts as JSON numbers, and PHP can only produce one
 * from an int or a float. Casting a decimal string to float is how
 * "1500.10" quietly becomes 1500.0999999 on some other system, so every cast
 * is checked: the number is encoded the way the body will be and must read
 * back as exactly the stored amount, or the amount is refused before any
 * HTTP call.
 *
 * PHP's JSON encoder follows the host's `serialize_precision`, and neither
 * Laravel nor Guzzle pins it: a php.ini that still carries 17 turns 1500.1
 * into 1500.0999999999999 on the wire. json() encodes with -1 (the shortest
 * form that reads back as the same double) whatever the host says, so
 * "1500.50000000" goes out as 1500.5 and "1500.00000000" as 1500, and drivers
 * send the body it returns rather than letting the HTTP client encode it.
 */
final class WireAmount
{
    /**
     * @throws UnrepresentableAmountException when the value is not a plain decimal, or cannot be sent exactly
     */
    public static function number(string $decimal): int|float
    {
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', trim($decimal), $parts) !== 1) {
            throw new UnrepresentableAmountException('The amount is not a plain decimal number.');
        }

        $whole     = ltrim($parts[2], '0') ?: '0';
        $fraction  = rtrim($parts[3] ?? '', '0');
        $canonical = ($parts[1] === '-' ? '-' : '').$whole.($fraction === '' ? '' : '.'.$fraction);

        if ($canonical === '-0') {
            $canonical = '0';
        }

        $cast = $fraction === '' && self::fitsInInteger($canonical) ? (int) $canonical : (float) $canonical;

        if (is_float($cast) && ! is_finite($cast)) {
            throw new UnrepresentableAmountException('The amount is too large to send.');
        }

        $scale = max(DecimalMoney::CALCULATION_SCALE, strlen($fraction));

        if (DecimalMoney::compare(DecimalMoney::normalize($cast, $scale), $canonical, $scale) !== 0) {
            throw new UnrepresentableAmountException('The amount cannot be sent exactly as a JSON number.');
        }

        try {
            $encoded = trim(self::json([$cast]), '[]');
        } catch (JsonException) {
            throw new UnrepresentableAmountException('The amount cannot be sent exactly as a JSON number.');
        }

        $written = self::plainDecimal($encoded);

        if ($written === null || DecimalMoney::compare($written, $canonical, $scale) !== 0) {
            throw new UnrepresentableAmountException('The amount cannot be sent exactly as a JSON number.');
        }

        return $cast;
    }

    /**
     * The exact decimal a JSON number's text denotes ("1.0e-8" is
     * "0.000000010"), or null for anything that is not a JSON number.
     */
    private static function plainDecimal(string $json): ?string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $json, $parts) !== 1) {
            return null;
        }

        $digits   = $parts[2].($parts[3] ?? '');
        $exponent = (int) ($parts[4] ?? 0);
        $point    = strlen($parts[2]) + $exponent;

        if (abs($exponent) > 400) {
            return null;
        }

        if ($point <= 0) {
            $decimal = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $decimal = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $decimal = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return $parts[1].$decimal;
    }

    /**
     * A JSON request body, with every float written as the shortest form
     * that reads back as the same double, whatever the host's
     * `serialize_precision`. Same flags as Guzzle's own `json` option, so
     * nothing else in the body changes. The setting is put back afterwards.
     *
     * @param array<array-key, mixed> $body
     *
     * @throws JsonException when the body cannot be encoded
     */
    public static function json(array $body): string
    {
        $previous = ini_get('serialize_precision');
        $changed  = $previous !== '-1' && ini_set('serialize_precision', '-1') !== false;

        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } finally {
            if ($changed) {
                ini_set('serialize_precision', (string) $previous);
            }
        }
    }

    private static function fitsInInteger(string $whole): bool
    {
        return bccomp($whole, (string) PHP_INT_MAX) <= 0 && bccomp($whole, (string) PHP_INT_MIN) >= 0;
    }
}
