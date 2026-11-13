<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\CurrencyRateRevision;
use App\Models\DepositMethod;
use App\Models\WithdrawMethod;
use App\Services\CurrencyConversionService;
use App\Services\CurrencyService;
use App\Support\DecimalMoney;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pull every live rate on a schedule instead of inside an accessor.
 *
 * Live rates used to be fetched lazily, from `getExchangeRateAttribute()`, on
 * whichever request happened to read the attribute first. That put a blocking
 * third-party HTTP call in the middle of rendering — a page listing ten
 * currencies opened ten of them — made `rate_updated_at` a record of when
 * someone last browsed rather than when the rate was last priced, and meant a
 * quiet site drifted indefinitely because nothing was reading.
 *
 * Refreshing on a schedule is how rates are meant to move: the fetch happens
 * once per currency per interval, off the request path, and `RateGuard`'s
 * staleness check becomes meaningful because the timestamp now measures the
 * feed rather than the traffic.
 *
 * A currency whose provider cannot price it keeps the rate it already had.
 * That is deliberate — overwriting a good rate with a zero, or with the
 * identity value a disabled provider returns, is how conversions silently
 * become wrong. The rate simply stops being refreshed, ages past
 * `rate_max_age_minutes`, and RateGuard blocks conversions on it.
 *
 * Every change is written to `currency_rate_revisions` so the rate a
 * transaction was priced at can be reconstructed later. Only changes are
 * recorded, not every poll.
 */
class RefreshExchangeRates extends Command
{
    protected $signature = 'currency:refresh-rates
                            {--dry-run : Report what would change without writing anything}';

    protected $description = 'Refresh every currency and payment method rate marked as live.';

    private int $updated = 0;

    private int $unchanged = 0;

    private int $failed = 0;

    public function handle(CurrencyConversionService $rates, CurrencyService $currencies): int
    {
        $base = (string) (Currency::query()->where('default', true)->value('code') ?? '');

        if ($base === '') {
            $this->components->error('No base currency is set, so there is nothing to quote rates against.');

            return self::FAILURE;
        }

        $batch = (string) Str::uuid();

        $this->refreshCurrencies($rates, $base, $batch);
        $this->refreshMethods($rates, $base);

        if (! $this->option('dry-run') && $this->updated > 0) {
            $currencies->clearCurrencyCache();
        }

        $this->components->info(sprintf(
            '%d updated, %d unchanged, %d could not be priced.',
            $this->updated,
            $this->unchanged,
            $this->failed,
        ));

        $this->reportWhyNothingHappened($rates);

        // A feed that priced nothing at all is an outage worth a non-zero exit
        // so the scheduler's failure hooks can see it. A partial failure is
        // not: one dead symbol must not mask the rest having worked.
        return $this->failed > 0 && $this->updated === 0 && $this->unchanged === 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Say why a run did nothing, rather than reporting three zeros.
     *
     * "0 updated, 0 unchanged, 0 could not be priced" is what this printed
     * when no currency was marked live, and again when the crypto plugin was
     * switched off — both read as a clean run. An operator who has just
     * enabled live rates on a currency and sees that reasonably concludes it
     * worked, and only finds out weeks later that RateGuard has been blocking
     * the currency the whole time because nothing ever refreshed it.
     *
     * Not an error: an install on deliberately static rates is a supported
     * configuration and its scheduled run must stay quiet in the logs. This
     * only names the cause when there was genuinely nothing to do.
     */
    private function reportWhyNothingHappened(CurrencyConversionService $rates): void
    {
        // A run that priced nothing because nothing asked to be priced. The
        // counters are the test: a live currency that failed still counts as
        // work, and that is a different diagnosis.
        if ($this->updated + $this->unchanged + $this->failed === 0) {
            $liveCurrencies = Currency::query()->where('rate_live', true)->where('default', false)->count();
            $liveMethods    = DepositMethod::query()->where('conversion_rate_live', true)->count()
                + WithdrawMethod::query()->where('conversion_rate_live', true)->count();

            if ($liveCurrencies === 0 && $liveMethods === 0) {
                $this->components->warn(
                    'Nothing is marked for live rates, so this run had no work to do. '
                    .'Turn on "Live" for a currency or a payment method to have it refreshed here.'
                );
            }

            return;
        }

        // Something asked to be priced and could not be. When the crypto
        // plugin is off that is not an outage to investigate, it is a setting
        // — and "could not be priced" on its own sends the operator looking in
        // the wrong place.
        if ($this->failed > 0 && ! $rates->cryptoRatesAvailable()) {
            $this->components->warn(
                'The CoinMarketCap plugin is disabled, so no crypto rate can be priced. '
                .'Enable it under the currency plugins, or set crypto rates manually.'
            );
        }
    }

    private function refreshCurrencies(CurrencyConversionService $rates, string $base, string $batch): void
    {
        $currencies = Currency::query()
            ->where('rate_live', true)
            ->where('default', false)
            ->orderBy('code')
            ->get();

        foreach ($currencies as $currency) {
            $fetched = $this->fetch($rates, $base, (string) $currency->code);

            if ($fetched === null) {
                continue;
            }

            $previous = DecimalMoney::rate((string) $currency->getRawOriginal('exchange_rate'));
            $changed  = bccomp($fetched, $previous, DecimalMoney::RATE_SCALE) !== 0;

            $this->report((string) $currency->code, $previous, $fetched, $changed);

            if ($this->option('dry-run')) {
                $changed ? $this->updated++ : $this->unchanged++;

                continue;
            }

            if ($changed) {
                CurrencyRateRevision::query()->create([
                    'batch_uuid'    => $batch,
                    'currency_id'   => $currency->id,
                    'currency_code' => $currency->code,
                    'old_rate'      => $previous,
                    'new_rate'      => $fetched,
                    'old_base_code' => $base,
                    'new_base_code' => $base,
                    'reason'        => 'live_refresh',
                ]);
            }

            // `rate_updated_at` is stamped whether or not the number moved: a
            // stable rate that keeps being confirmed is fresh, and letting it
            // age would have RateGuard block a perfectly working feed.
            $this->persist($currency, [
                'exchange_rate'   => $fetched,
                'rate_updated_at' => now(),
                'rate_source'     => 'live',
            ], $changed);
        }
    }

    private function refreshMethods(CurrencyConversionService $rates, string $base): void
    {
        foreach ([DepositMethod::class, WithdrawMethod::class] as $model) {
            $methods = $model::query()
                ->where('conversion_rate_live', true)
                ->orderBy('name')
                ->get();

            foreach ($methods as $method) {
                $fetched = $this->fetch($rates, $base, (string) $method->currency);

                if ($fetched === null) {
                    continue;
                }

                // `?? 0` rather than a bare cast: the column is NULLABLE on
                // installs whose schema predates the convergence migration, and
                // NULL casts to an empty string that bcmath rejects outright —
                // aborting the whole refresh over one unpriced method.
                $previous = DecimalMoney::rate((string) ($method->getRawOriginal('conversion_rate') ?? 0));
                $changed  = bccomp($fetched, $previous, DecimalMoney::RATE_SCALE) !== 0;

                $this->report($method->name.' ('.$method->currency.')', $previous, $fetched, $changed);

                if ($this->option('dry-run')) {
                    $changed ? $this->updated++ : $this->unchanged++;

                    continue;
                }

                // Stamped whether or not the number moved, for the same reason
                // currencies are: a stable rate that keeps being confirmed is
                // fresh, and letting it age would report a working feed as
                // stale on the dashboard.
                $this->persist($method, [
                    'conversion_rate'            => $fetched,
                    'conversion_rate_updated_at' => now(),
                ], $changed);
            }
        }
    }

    /**
     * A rate we could not obtain, or one that came back non-positive, is not a
     * rate. Returning null leaves the configured value in place.
     */
    private function fetch(CurrencyConversionService $rates, string $base, string $code): ?string
    {
        if ($code === '' || strtoupper($code) === strtoupper($base)) {
            return null;
        }

        try {
            $rate = $rates->convertCurrency(1, $base, $code);
        } catch (Throwable $e) {
            $this->components->warn("{$code}: {$e->getMessage()}");
            $this->failed++;

            return null;
        }

        if ($rate === null || $rate <= 0) {
            $this->components->warn("{$code}: the provider did not return a usable rate; keeping the configured one.");
            $this->failed++;

            return null;
        }

        return DecimalMoney::rate($rate);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function persist(Model $record, array $attributes, bool $changed): void
    {
        $record->forceFill($attributes)->save();

        $changed ? $this->updated++ : $this->unchanged++;
    }

    private function report(string $label, string $previous, string $fetched, bool $changed): void
    {
        if (! $this->output->isVerbose() && ! $changed) {
            return;
        }

        $this->components->twoColumnDetail(
            $label,
            $changed ? "{$previous} -> {$fetched}" : $fetched.' (unchanged)',
        );
    }
}
