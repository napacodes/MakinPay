<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How a monetary figure crosses the wire to the app.
 *
 * Always a string, never a JSON number: a number reaches Dart through a
 * double, and a double cannot hold 0.1. Survivable in a chart, not survivable
 * in a ledger.
 *
 * Always at the precision of the currency the figure is actually denominated
 * in, and never at a fallback. Money::decimals() falls back to the base
 * currency when a code resolves to nothing, which is right for a screen and
 * wrong here: a transaction names its currency in a plain string column, so
 * the row can outlive the `currencies` record it refers to, and quantizing
 * 0.00000941 BTC at the base currency's two places would report it to a
 * customer as 0.00.
 *
 * When the platform no longer knows the currency, the stored value is passed
 * through at ledger scale and `decimals` is null - the app is told the
 * precision is unknown rather than told a wrong one.
 */
final class ApiMoney
{
    /**
     * @return array{value: string, decimals: int|null}
     */
    public static function describe(string|int|float|null $amount, ?string $currencyCode): array
    {
        $decimals = Money::decimalsIfKnown($currencyCode);

        return [
            'value' => $decimals === null
                ? DecimalMoney::normalize($amount ?? 0)
                : Money::quantize($amount, $currencyCode),
            'decimals' => $decimals,
        ];
    }

    /**
     * Just the figure, for a payload that reports the precision once alongside
     * several amounts in the same currency.
     */
    public static function amount(string|int|float|null $amount, ?string $currencyCode): string
    {
        return self::describe($amount, $currencyCode)['value'];
    }

    public static function decimals(?string $currencyCode): ?int
    {
        return Money::decimalsIfKnown($currencyCode);
    }
}
