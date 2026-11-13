<?php

namespace App\Services;

use App\Models\Currency;
use App\Support\DecimalMoney;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CurrencyService
{
    public const string DEFAULT_CURRENCY_CACHE_KEY = 'default_currency';

    public const string ALL_CURRENCIES_CACHE_KEY = 'all_currencies';

    /**
     * Latches to true once the currencies table is known to exist.
     */
    private static ?bool $currenciesTableExists = null;

    /**
     * Get the default currency code, cached for efficiency.
     */
    public function getDefaultCurrency(): array
    {
        /*
         * The schema probe is memoised once it answers yes. Money::format()
         * lands here for every amount on a page, and each miss was an
         * information_schema round trip. Only true is remembered: a table that
         * exists cannot vanish, while a false answer has to stay live so the
         * installer sees the table the moment it creates one.
         */
        if (self::$currenciesTableExists !== true) {
            if (! Schema::hasTable('currencies')) {
                return [];
            }

            self::$currenciesTableExists = true;
        }

        return Cache::remember(self::DEFAULT_CURRENCY_CACHE_KEY, now()->addDay(), function () {
            // The whole row is cached for a day so Money::format() does not fire
            // a query per formatted amount. Deliberately not a column projection:
            // one would have to be edited in lockstep with every display column
            // added to `currencies`, and a buyer mid-upgrade would hit an
            // unknown-column error before the new migration had run.
            return Currency::query()
                ->where('default', true)
                ->first()
                ?->toArray() ?? [];
        });
    }

    public function exists($currencyCode): bool
    {
        return Currency::where('code', $currencyCode)->exists();
    }

    /**
     * Get a list of all active currencies, cached.
     */
    public function getAllCurrencies()
    {
        return Cache::remember(self::ALL_CURRENCIES_CACHE_KEY, now()->addDay(), function () {
            return Currency::where('status', true)->get();
        });
    }

    public function getCurrencyByCode($code): ?Currency
    {
        $code = strtoupper(trim((string) $code));

        return Cache::remember(self::currencyCacheKey($code), now()->addDay(), function () use ($code) {
            return Currency::where('code', $code)->first();
        });
    }

    public static function currencyCacheKey(string $code): string
    {
        return 'currency_by_code_'.strtoupper(trim($code));
    }

    /**
     * Stored exchange rates for a set of currency codes, keyed by code.
     *
     * One query for the whole set, and the rates come back as exact decimal
     * strings so a satoshi-scale rate is not flattened on the way out.
     *
     * @param  iterable<mixed>            $codes
     * @return Collection<string, string>
     */
    public function ratesByCode(iterable $codes): Collection
    {
        $codes = collect($codes)
            ->filter()
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        return Currency::query()
            ->whereIn('code', $codes)
            ->get(['code', 'exchange_rate'])
            ->mapWithKeys(fn (Currency $currency): array => [
                strtoupper((string) $currency->code) => DecimalMoney::rate($currency->getRawOriginal('exchange_rate')),
            ]);
    }

    /**
     * Restate an amount denominated in `$currencyCode` into the base currency.
     *
     * `exchange_rate` means "1 unit of the base buys N of this currency", so
     * the trip back to base is a division. This is the one implementation:
     * reporting used to have three, and one of them — the admin transaction
     * summary — reached for a live third-party quote per ledger row instead of
     * the stored rate, which both burned an API call per transaction and made
     * a reporting page disagree with the ledger it was reporting on.
     *
     * An unknown or unusable rate leaves the amount alone rather than dropping
     * the row: a total that silently omits a currency is worse than one that
     * is approximate, and `money:audit-precision` is what reports the bad rate.
     *
     * @param Collection<string, string> $rates from {@see ratesByCode()}
     */
    public function toBaseAmount(string|int|float|null $amount, ?string $currencyCode, Collection $rates): string
    {
        $amount = DecimalMoney::normalize($amount ?? 0);
        $code   = strtoupper(trim((string) $currencyCode));

        if ($code === '' || $code === strtoupper((string) ($this->getDefaultCurrency()['code'] ?? ''))) {
            return $amount;
        }

        $rate = $rates->get($code);

        if ($rate === null || ! is_numeric($rate) || bccomp(DecimalMoney::rate($rate), '0', DecimalMoney::RATE_SCALE) <= 0) {
            return $amount;
        }

        return DecimalMoney::divide($amount, $rate);
    }

    /**
     * Present a base-currency amount in another currency.
     *
     * The inverse of {@see toBaseAmount()}: `exchange_rate` means "1 unit of
     * the base buys N of this currency", so the trip out of base is a
     * multiplication.
     *
     * Presentation only — nothing that reaches the ledger goes through here.
     * An unknown or unusable rate returns the amount untouched rather than a
     * figure derived from a rate nobody trusts, which is the same choice
     * `toBaseAmount()` makes in the other direction.
     */
    public function fromBaseAmount(string|int|float|null $amount, ?string $currencyCode): string
    {
        $amount = DecimalMoney::normalize($amount ?? 0);
        $code   = strtoupper(trim((string) $currencyCode));

        if ($code === '' || $code === strtoupper((string) ($this->getDefaultCurrency()['code'] ?? ''))) {
            return $amount;
        }

        $rate = $this->ratesByCode([$code])->get($code);

        if ($rate === null || bccomp(DecimalMoney::rate($rate), '0', DecimalMoney::RATE_SCALE) <= 0) {
            return $amount;
        }

        return DecimalMoney::multiply($amount, $rate);
    }

    /**
     * How many units of `$to` one unit of `$from` buys, or null when either
     * side has no usable stored rate.
     *
     * Every rate is held against the base — r(x) is "1 base buys r(x) of x" —
     * so a cross rate is r(to) / r(from), and the base cancels out. Identical
     * codes short-circuit to exactly 1 rather than to a rounded quotient.
     */
    public function crossRate(string $from, string $to): ?string
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        if ($from === '' || $to === '') {
            return null;
        }

        if ($from === $to) {
            return '1';
        }

        $rates = $this->ratesByCode([$from, $to]);

        $fromRate = $rates->get($from);
        $toRate   = $rates->get($to);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        if (bccomp(DecimalMoney::rate($fromRate), '0', DecimalMoney::RATE_SCALE) <= 0) {
            return null;
        }

        return DecimalMoney::rate(bcdiv(
            DecimalMoney::rate($toRate),
            DecimalMoney::rate($fromRate),
            DecimalMoney::RATE_SCALE,
        ));
    }

    /**
     * Sum one column of a result set in the base currency, exactly.
     *
     * Accumulating through bcmath rather than float addition: these totals run
     * over every ledger row in a period, and float error compounds per row.
     *
     * @param Collection<int, object> $rows
     */
    public function sumInBaseCurrency(Collection $rows, string $column, ?Collection $rates = null): string
    {
        $rates ??= $this->ratesByCode($rows->pluck('currency'));

        return $rows->reduce(
            fn (string $carry, $row): string => DecimalMoney::add(
                $carry,
                $this->toBaseAmount($row->{$column} ?? 0, $row->currency ?? null, $rates),
                DecimalMoney::CALCULATION_SCALE,
            ),
            DecimalMoney::normalize(0),
        );
    }

    /**
     * Clear cached currency data.
     *
     * The per-code entries must be forgotten too. Without this, editing a
     * currency's symbol or decimal places in admin had no visible effect for a
     * full day because `currency_by_code_*` kept serving the stale row — the
     * cached copy could even predate a schema change.
     */
    public function clearCurrencyCache(string ...$alsoForget): void
    {
        Cache::forget(self::DEFAULT_CURRENCY_CACHE_KEY);
        Cache::forget(self::ALL_CURRENCIES_CACHE_KEY);

        // Codes of rows that are gone, or being renamed, are passed in
        // explicitly — a table scan alone would never reach them.
        foreach ($alsoForget as $code) {
            Cache::forget(self::currencyCacheKey($code));
        }

        if (! Schema::hasTable('currencies')) {
            return;
        }

        Currency::query()->pluck('code')->each(
            fn ($code) => Cache::forget(self::currencyCacheKey((string) $code))
        );
    }
}
