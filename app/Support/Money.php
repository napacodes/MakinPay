<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Currency;
use App\Services\CurrencyService;
use Throwable;

/**
 * The single place an amount becomes a string for display.
 *
 * Before this existed there were ~479 bespoke number_format() calls in views,
 * 4 out of 5 of them hardcoding 2 decimals — which is why a BTC balance
 * rendered as "0.00". The global `site_decimal` setting remains as the
 * fallback for amounts with no currency context; per-currency precision wins
 * whenever a currency is known.
 *
 * Placement and grouping are per-currency too, and both default to what this
 * class did before those columns existed (prefix symbol, thousands grouping),
 * so an upgraded installation renders identically until a currency is edited.
 */
class Money
{
    /**
     * Fee components are stored as decimal(24,8); nothing beyond that scale is
     * meaningful to display.
     */
    public const FEE_SCALE = 8;

    public const POSITION_PREFIX = 'prefix';

    public const POSITION_SUFFIX = 'suffix';

    public const GROUPING_STANDARD = 'standard';

    public const GROUPING_INDIAN = 'indian';

    /**
     * Format an amount at its currency's own precision, with that currency's
     * own symbol — not the site base currency's.
     */
    public static function format(string|int|float|null $amount, ?string $currencyCode = null, bool $withSymbol = true): string
    {
        $currency  = self::resolveSafely($currencyCode);
        $formatted = self::group(
            (float) ($amount ?? 0),
            self::decimalsFor($currency),
            self::attribute($currency, 'number_grouping', self::GROUPING_STANDARD)
        );

        if (! $withSymbol) {
            return $formatted;
        }

        return self::place($formatted, self::symbolFor($currency), $currency);
    }

    /**
     * Shorten a large amount for a headline figure: 1.28M, 4.2k, 2.45 Cr.
     *
     * Display only — never a figure anything reconciles against. A currency
     * that groups in lakh/crore gets crore rather than millions, because
     * "12M BDT" reads as a translation error to the operator it is written for.
     */
    public static function compact(string|int|float|null $amount, ?string $currencyCode = null, bool $withSymbol = true): string
    {
        $currency  = self::resolveSafely($currencyCode);
        $value     = (float) ($amount ?? 0);
        $sign      = $value < 0 ? '-' : '';
        $magnitude = abs($value);
        $indian    = self::attribute($currency, 'number_grouping', self::GROUPING_STANDARD) === self::GROUPING_INDIAN;

        if ($indian && $magnitude >= 10000000) {
            $short = number_format($magnitude / 10000000, 2).' Cr';
        } elseif ($magnitude >= 1000000) {
            $short = number_format($magnitude / 1000000, 2).'M';
        } elseif ($magnitude >= 1000) {
            $short = number_format($magnitude / 1000, 1).'k';
        } else {
            $short = self::group(
                $magnitude,
                self::decimalsFor($currency),
                $indian ? self::GROUPING_INDIAN : self::GROUPING_STANDARD
            );
        }

        if (! $withSymbol) {
            return $sign.$short;
        }

        return self::place($sign.$short, self::symbolFor($currency), $currency);
    }

    /**
     * Format an exchange rate. Rates are not amounts: they keep their own
     * scale and drop insignificant zeros so 1 reads as "1" while a live BTC
     * rate reads as "0.0000094118".
     */
    public static function formatRate(string|int|float|null $rate): string
    {
        return DecimalMoney::rate($rate ?? 0);
    }

    /**
     * Describe a configured fee the way a customer reads it: "$0.3 + 2.9%", or
     * just the component that is actually set.
     *
     * The columns are decimal(24,8), so a 1% fee arrives as "1.00000000" and
     * would otherwise be shown exactly like that. This is the server-side twin
     * of DigiKashMoney.describe() in public/frontend/js/digikash-money.js —
     * the two must agree byte for byte, or the same fee reads differently on
     * two pages.
     */
    public static function describeFee(string|int|float|null $fixed, string|int|float|null $percent, string $symbol = ''): string
    {
        $parts = [];

        if ((float) ($fixed ?? 0) > 0) {
            $parts[] = self::trimFee($fixed).($symbol === '' ? '' : ' '.$symbol);
        }

        if ((float) ($percent ?? 0) > 0) {
            $parts[] = self::trimFee($percent).'%';
        }

        return $parts === [] ? '0' : implode(' + ', $parts);
    }

    /**
     * Render a configured rate or flat amount without its storage padding.
     */
    public static function trimFee(string|int|float|null $value): string
    {
        $normalized = number_format((float) ($value ?? 0), self::FEE_SCALE, '.', '');

        if (! str_contains($normalized, '.')) {
            return $normalized;
        }

        return rtrim(rtrim($normalized, '0'), '.') ?: '0';
    }

    /**
     * Round an amount to its currency's precision without formatting it —
     * for values about to be stored or compared rather than displayed.
     */
    public static function quantize(string|int|float|null $amount, ?string $currencyCode = null): string
    {
        return DecimalMoney::round($amount ?? 0, self::decimals($currencyCode));
    }

    /**
     * The IAS 21 snapshot of a wallet-denominated amount and fee as decimal
     * strings: the wallet side is the figure itself, the base side is it
     * divided by the rate ({@see DecimalMoney::toBase()}). Shared by every
     * ledger that writes wallet-currency rows (bill payments, mass payments,
     * virtual cards) so they cannot drift apart.
     *
     * @return array{base_currency: string|null, wallet_amount: string, wallet_fee: string, base_amount: string, base_fee: string}
     */
    public static function fxSnapshot(string $amount, string $fee, string $rate): array
    {
        $scale = DecimalMoney::CALCULATION_SCALE;

        return [
            'base_currency' => siteCurrency(),
            'wallet_amount' => DecimalMoney::normalize($amount, $scale),
            'wallet_fee'    => DecimalMoney::normalize($fee, $scale),
            'base_amount'   => DecimalMoney::toBase($amount, $rate),
            'base_fee'      => DecimalMoney::toBase($fee, $rate),
        ];
    }

    /**
     * Resolve display precision for a currency code.
     *
     * Falls back through: the currency's own decimal_places, the site default
     * currency's, then the legacy global `site_decimal` setting.
     */
    public static function decimals(?string $currencyCode = null): int
    {
        return self::decimalsFor(self::resolveSafely($currencyCode));
    }

    /**
     * The precision of a currency the platform actually knows, or null when
     * the code resolves to nothing.
     *
     * Formatting can live with {@see decimals()} falling back to the base
     * currency — a figure rendered at two places is merely wrong on screen.
     * Money that moves cannot: quantizing a BTC payout at the base currency's
     * two places writes 0.000132 away as 0. A payment method names its
     * currency in a plain string column, so it can outlive the `currencies`
     * row it refers to — which is what `money:audit-precision` reports — and a
     * caller about to round a real payment has to tell "two decimals" apart
     * from "I have no idea".
     */
    public static function decimalsIfKnown(?string $currencyCode): ?int
    {
        $currency = self::resolveSafely($currencyCode);

        return $currency === null ? null : self::decimalsFor($currency);
    }

    public static function symbol(?string $currencyCode = null): string
    {
        return self::symbolFor(self::resolveSafely($currencyCode));
    }

    /**
     * Where this currency's symbol sits: `prefix` or `suffix`.
     */
    public static function symbolPosition(?string $currencyCode = null): string
    {
        $position = self::attribute(self::resolveSafely($currencyCode), 'symbol_position', self::POSITION_PREFIX);

        return $position === self::POSITION_SUFFIX ? self::POSITION_SUFFIX : self::POSITION_PREFIX;
    }

    /**
     * How this currency groups digits: `standard` or `indian`.
     */
    public static function grouping(?string $currencyCode = null): string
    {
        $grouping = self::attribute(self::resolveSafely($currencyCode), 'number_grouping', self::GROUPING_STANDARD);

        return $grouping === self::GROUPING_INDIAN ? self::GROUPING_INDIAN : self::GROUPING_STANDARD;
    }

    /**
     * Group the integer part. Indian grouping is the last three digits, then
     * pairs — 1,28,49,300 rather than 128,49,300.
     */
    private static function group(float $amount, int $decimals, string $grouping): string
    {
        $formatted = number_format($amount, $decimals);

        if ($grouping !== self::GROUPING_INDIAN) {
            return $formatted;
        }

        $fraction = null;
        $integer  = $formatted;

        if (str_contains($formatted, '.')) {
            [$integer, $fraction] = explode('.', $formatted, 2);
        }

        $sign = '';

        if (str_starts_with($integer, '-')) {
            $sign    = '-';
            $integer = substr($integer, 1);
        }

        $integer = str_replace(',', '', $integer);

        if (strlen($integer) > 3) {
            $lastThree = substr($integer, -3);
            $rest      = (string) preg_replace('/\B(?=(\d\d)+$)/', ',', substr($integer, 0, -3));
            $integer   = $rest.','.$lastThree;
        }

        return $sign.$integer.($fraction === null ? '' : '.'.$fraction);
    }

    /**
     * @param Currency|array<string, mixed>|null $currency
     */
    private static function place(string $formatted, string $symbol, Currency|array|null $currency): string
    {
        if ($symbol === '') {
            return $formatted;
        }

        if (self::attribute($currency, 'symbol_position', self::POSITION_PREFIX) === self::POSITION_SUFFIX) {
            return $formatted.' '.$symbol;
        }

        return $symbol.$formatted;
    }

    /**
     * @param Currency|array<string, mixed>|null $currency
     */
    private static function decimalsFor(Currency|array|null $currency): int
    {
        $fallback = CurrencyPrecision::clamp(setting('site_decimal', CurrencyPrecision::DEFAULT_FIAT_SCALE));

        if ($currency === null) {
            return $fallback;
        }

        $places = is_array($currency) ? ($currency['decimal_places'] ?? null) : $currency->decimal_places;

        return $places === null ? $fallback : CurrencyPrecision::clamp($places, $fallback);
    }

    /**
     * @param Currency|array<string, mixed>|null $currency
     */
    private static function symbolFor(Currency|array|null $currency): string
    {
        if ($currency === null) {
            return (string) (siteCurrency('symbol') ?? '');
        }

        return (string) (is_array($currency) ? ($currency['symbol'] ?? '') : $currency->symbol);
    }

    /**
     * Read one display attribute, tolerating a database that predates the
     * column: a missing or blank value means "behave as this class always has".
     *
     * @param Currency|array<string, mixed>|null $currency
     */
    private static function attribute(Currency|array|null $currency, string $column, string $default): string
    {
        if ($currency === null) {
            return $default;
        }

        $value = is_array($currency)
            ? ($currency[$column] ?? null)
            : $currency->getAttribute($column);

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? $default : $value;
    }

    /**
     * @return Currency|array<string, mixed>|null
     */
    private static function resolveSafely(?string $currencyCode): Currency|array|null
    {
        try {
            return self::resolve($currencyCode);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return Currency|array<string, mixed>|null
     */
    private static function resolve(?string $currencyCode): Currency|array|null
    {
        $service = app(CurrencyService::class);

        if ($currencyCode === null || trim($currencyCode) === '') {
            $default = $service->getDefaultCurrency();

            return $default === [] ? null : $default;
        }

        return $service->getCurrencyByCode(strtoupper(trim($currencyCode)));
    }
}
