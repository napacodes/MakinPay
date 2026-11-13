<?php

namespace App\Support;

use InvalidArgumentException;

class DecimalMoney
{
    public const CALCULATION_SCALE = 8;

    public const AMOUNT_SCALE = 2;

    /**
     * Scale for exchange rates.
     *
     * A rate is not an amount and must never be formatted at amount scale:
     * 1 USD buys roughly 0.0000094 BTC, which any 2-decimal format collapses to
     * zero, destroying the conversion rather than truncating it.
     *
     * 18 rather than a narrower scale because what matters for a rate is
     * *relative* precision. Against a low-denomination base such as IDR, the
     * BTC rate is around 5.9e-10 — at 12 decimals that retains only three
     * significant digits and injects conversion errors of a fifth of a percent.
     */
    public const RATE_SCALE = 18;

    public static function normalize(string|int|float $value, int $scale = self::CALCULATION_SCALE): string
    {
        if (! is_numeric($value)) {
            // Only a string can reach this branch — every int and float is
            // numeric — so name the offending value. Without it the caller
            // sees "must be numeric" with no clue which of the rates, fees or
            // settings it walked through was blank or formatted.
            throw new InvalidArgumentException(sprintf(
                'Money value must be numeric, got %s (%s).',
                self::describe($value),
                get_debug_type($value),
            ));
        }

        // A float is converted at its own precision, not at the target scale.
        // Printing 0.7 to eighteen places gives 0.699999999999999956 — the
        // double's true value — and bcmath then faithfully carries that error
        // into the product: a 0.7 fee times a rate of 16.63 came out as
        // 11.64099999 instead of 11.641.
        $normalized = is_float($value) ? self::fromFloat($value) : (string) $value;

        // bcmath cannot parse exponential notation, and small rates arrive in
        // that form from drivers that hand back "9.4118E-6" for a REAL
        // column. Expand those to plain decimal.
        if (stripos($normalized, 'e') !== false) {
            $normalized = number_format((float) $normalized, self::RATE_SCALE, '.', '');
        }

        return bcadd($normalized, '0', $scale);
    }

    /**
     * A float as the plain decimal string it stands for, with every digit the
     * double holds and nothing added: no scaling, no rounding, no exponent.
     *
     * This is the one safe way to turn a float into a string on its way to a
     * decimal column. `(string) $float`, which is also what PDO does to a
     * float binding, goes through the `precision` ini setting - 14 digits by
     * default - so 1234567.12345678 was written as 1234567.1234568 into a
     * column that could hold it exactly. Exponent forms are expanded because
     * MySQL reads "1.0E-8" as a double and SQLite as text.
     */
    public static function fromFloat(float $value): string
    {
        $string = self::shortestRepresentation($value);

        if (stripos($string, 'e') !== false) {
            $string = rtrim(rtrim(number_format($value, self::RATE_SCALE, '.', ''), '0'), '.');
        }

        return $string === '' || $string === '-' ? '0' : $string;
    }

    /**
     * The shortest decimal string that reads back as the same double.
     *
     * This is what `serialize_precision = -1` produces, but derived rather than
     * read from php.ini so the arithmetic cannot change with a host's
     * configuration. Seventeen significant digits always round-trip a double,
     * so the loop terminates.
     */
    private static function shortestRepresentation(float $value): string
    {
        for ($precision = 1; $precision < 17; $precision++) {
            $candidate = sprintf('%.*G', $precision, $value);

            if ((float) $candidate === $value) {
                return $candidate;
            }
        }

        return sprintf('%.17G', $value);
    }

    /**
     * Normalize an exchange rate at RATE_SCALE and drop insignificant trailing
     * zeros, so a rate keeps full precision while still reading cleanly:
     * 1 stays "1", and a live BTC rate stays "0.0000094118".
     */
    public static function rate(string|int|float $value): string
    {
        $normalized = self::normalize($value, self::RATE_SCALE);

        if (! str_contains($normalized, '.')) {
            return $normalized;
        }

        return rtrim(rtrim($normalized, '0'), '.') ?: '0';
    }

    public static function add(string|int|float $left, string|int|float $right, int $scale = self::AMOUNT_SCALE): string
    {
        return bcadd(self::normalize($left, self::CALCULATION_SCALE), self::normalize($right, self::CALCULATION_SCALE), $scale);
    }

    public static function subtract(string|int|float $left, string|int|float $right, int $scale = self::AMOUNT_SCALE): string
    {
        return bcsub(self::normalize($left, self::CALCULATION_SCALE), self::normalize($right, self::CALCULATION_SCALE), $scale);
    }

    /**
     * Multiply, keeping both operands whole.
     *
     * The operands are normalized at RATE_SCALE, not at the result's scale.
     * Normalizing them at CALCULATION_SCALE — which this used to do — truncated
     * an exchange rate before it was ever used: 0.0000094118 became 0.00000941,
     * and 1000 USD converted to 0.00941 BTC instead of 0.0094118, an error of
     * two tenths of a percent on every conversion and on every FX snapshot
     * derived from one.
     *
     * Truncation belongs on the product, where the caller asked for it, not on
     * the inputs.
     */
    public static function multiply(string|int|float $left, string|int|float $right, int $scale = self::CALCULATION_SCALE): string
    {
        return bcmul(
            self::normalize($left, self::RATE_SCALE),
            self::normalize($right, self::RATE_SCALE),
            $scale,
        );
    }

    /**
     * Divide, keeping the divisor whole and rounding rather than truncating.
     *
     * The counterpart to {@see multiply()}: operands are normalized at
     * RATE_SCALE so an exchange rate survives, and bcdiv is given two extra
     * digits of headroom because it truncates — dividing 1 by 3 at scale 8
     * yields 0.33333333, and rounding that back is the only way the last digit
     * is correct.
     *
     * A zero divisor has no answer. Callers converting through a rate must
     * establish the rate is usable first — RateGuard is that check — so
     * returning 0 here would hide a broken conversion behind a plausible
     * number.
     *
     * @throws InvalidArgumentException when the divisor is zero
     */
    public static function divide(string|int|float $dividend, string|int|float $divisor, int $scale = self::CALCULATION_SCALE): string
    {
        $divisor = self::normalize($divisor, self::RATE_SCALE);

        if (bccomp($divisor, '0', self::RATE_SCALE) === 0) {
            throw new InvalidArgumentException('Cannot divide a money value by zero.');
        }

        return self::round(
            bcdiv(self::normalize($dividend, self::RATE_SCALE), $divisor, $scale + 2),
            $scale,
        );
    }

    /**
     * A wallet-currency figure in the site currency, at the ledger's scale:
     * divided by the rate (1 base buys `$rate` wallet units). bcdiv
     * truncates, hence the two digits of headroom before rounding back. A
     * rate that is not positive leaves the figure as it is, normalized.
     */
    public static function toBase(string $value, string $rate): string
    {
        $scale = self::CALCULATION_SCALE;

        if (self::compare($rate, 0) <= 0) {
            return self::normalize($value, $scale);
        }

        return self::round(bcdiv($value, $rate, $scale + 2), $scale);
    }

    /**
     * Worked out at the calculation scale, or at `$scale` when a caller asks
     * for more, so a scale above eight is honoured rather than silently cut
     * back to eight places.
     */
    public static function percentage(string|int|float $amount, string|int|float $percentage, int $scale = self::AMOUNT_SCALE): string
    {
        $workingScale = max(self::CALCULATION_SCALE, $scale);
        $result       = bcdiv(self::multiply($amount, $percentage, $workingScale), '100', $workingScale);

        return self::round($result, $scale);
    }

    public static function subtractPercentage(string|int|float $amount, string|int|float $percentage, int $scale = self::CALCULATION_SCALE): string
    {
        $deduction = self::percentage($amount, $percentage, self::CALCULATION_SCALE);

        return bcsub(self::normalize($amount), $deduction, $scale);
    }

    public static function compare(string|int|float $left, string|int|float $right, int $scale = self::CALCULATION_SCALE): int
    {
        return bccomp(self::normalize($left, $scale), self::normalize($right, $scale), $scale);
    }

    /**
     * Render a rejected value for the exception message: quoted, whitespace
     * made visible, and short enough that it cannot flood a log line.
     */
    private static function describe(string|int|float $value): string
    {
        $text = (string) $value;

        if ($text === '') {
            return 'an empty string';
        }

        if (trim($text) === '') {
            return 'a blank string';
        }

        return '"'.(mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text).'"';
    }

    /**
     * Round half-up to the given scale.
     *
     * bcmath has no rounding of its own — bcadd/bcsub truncate — so a half
     * unit is added first and the truncation does the rounding.
     *
     * The working precision has to keep at least one digit past the target
     * scale, or the deciding digit is discarded before it can be read. This
     * used to normalize at CALCULATION_SCALE unconditionally, which made every
     * call at scale 8 or more a silent truncation: rounding 1.999999999 to 8
     * decimals returned 1.99999999 rather than 2. For any scale below 8 the
     * working precision is unchanged, so existing callers round exactly as
     * they did before.
     */
    public static function round(string|int|float $value, int $scale = self::AMOUNT_SCALE): string
    {
        $normalized = self::normalize($value, max(self::CALCULATION_SCALE, $scale + 1));
        $increment  = '0.'.str_repeat('0', $scale).'5';

        if (str_starts_with($normalized, '-')) {
            return bcsub($normalized, $increment, $scale);
        }

        return bcadd($normalized, $increment, $scale);
    }
}
