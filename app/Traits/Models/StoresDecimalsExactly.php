<?php

declare(strict_types=1);

namespace App\Traits\Models;

use App\Support\DecimalMoney;
use InvalidArgumentException;

/**
 * A float written to a decimal column keeps every digit it holds.
 *
 * The money columns are decimal(24,8) and exact, but a float reaches them
 * through PHP's string conversion - PDO binds a float as `(string) $value`,
 * which honours the `precision` ini setting, 14 digits by default. So a
 * writer that handed over 1234567.12345678 stored 1234567.1234568, in a
 * column that had room for the whole figure, with no error anywhere.
 *
 * Every float set on a `decimal:` cast attribute is therefore converted here,
 * before it is bound, through DecimalMoney::fromFloat(): the shortest plain
 * string that reads back as the same double, which is all the digits the
 * float ever had. Strings and ints pass through untouched - a decimal string
 * is already the exact form, and this must never narrow one.
 *
 * A string that is not a number is refused at the same point: "abc" or
 * "12.3.4" would otherwise reach the column as 0 or as text, depending on
 * the database and its strictness. A blank string means nothing was given
 * and becomes null. A numeric string in exponent form
 * ("1.0E-8", which a driver can hand back for a REAL column) is expanded to
 * plain decimal, since MySQL reads it as a double and SQLite as text.
 *
 * This is the safety net at the model boundary, not the rule. The rule is
 * that money travels as decimal strings (DecimalMoney), and the flows that
 * computed in floats have been moved to it; this catches what any other
 * writer, or a reader of an untyped column, still hands over.
 */
trait StoresDecimalsExactly
{
    /**
     * Bind a decimal-cast attribute as an exact decimal string.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return mixed
     *
     * @throws InvalidArgumentException for a string that is not a number
     */
    public function setAttribute($key, $value)
    {
        if ($this->isDecimalCast($this->getCasts()[$key] ?? '')) {
            if (is_float($value)) {
                $value = DecimalMoney::fromFloat($value);
            } elseif (is_string($value)) {
                $value = $this->exactDecimalString($key, $value);
            }
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Read a decimal column that the driver handed back as a float at the
     * float's own precision.
     *
     * MySQL returns a decimal column as a string, so this changes nothing
     * there. SQLite (the test database) stores it as a double and returns a
     * float, and the framework's cast printed that float through
     * `(string)` - `precision` 14 again - so a balance of 1234567.12345678
     * read back as 1234567.12345680 and a short wallet passed its own
     * sufficiency check.
     *
     * @param  mixed  $value
     * @param  int    $decimals
     * @return string
     */
    protected function asDecimal($value, $decimals)
    {
        return parent::asDecimal(is_float($value) ? DecimalMoney::fromFloat($value) : $value, $decimals);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function exactDecimalString(string $key, string $value): ?string
    {
        $value = trim($value);

        // Nothing was given: a blank form field or an absent rate. Stored as
        // null, which is what it means, rather than refused or written as 0.
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('%s.%s must be a decimal number, got "%s".', $this->getTable(), $key, $value));
        }

        if (stripos($value, 'e') === false) {
            return $value;
        }

        $plain = rtrim(rtrim(number_format((float) $value, DecimalMoney::RATE_SCALE, '.', ''), '0'), '.');

        return $plain === '' || $plain === '-' ? '0' : $plain;
    }
}
