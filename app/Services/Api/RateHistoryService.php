<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\Currency;
use App\Models\CurrencyRateRevision;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How many TO one FROM bought, day by day, for the exchange screen's chart.
 *
 * Nothing new is recorded for this. Every change to a live rate already
 * lands in `currency_rate_revisions` with the figure before and after, so
 * the rate at any past moment is the last revision before it - and before
 * the first revision, the figure that revision replaced. A currency whose
 * rate never moved has one point, which the app reads as "not enough
 * history" rather than drawing a flat line as if it knew.
 *
 * Rates are base-per-unit: `exchange_rate` on a currency is how many of it
 * one base unit buys. The cross rate is therefore TO's rate over FROM's.
 */
class RateHistoryService
{
    public const RANGES = ['1D' => 1, '1W' => 7, '1M' => 30, '1Y' => 365];

    /**
     * @return array{from: string, to: string, range: string, points: list<array{at: string, rate: string}>, average: string|null, change_percent: string|null, current: string|null}
     */
    public function series(string $from, string $to, string $range): array
    {
        $from  = strtoupper(trim($from));
        $to    = strtoupper(trim($to));
        $range = array_key_exists($range, self::RANGES) ? $range : '1M';
        $days  = self::RANGES[$range];

        $now   = CarbonImmutable::now();
        $start = $now->subDays($days);

        $fromCurrency = Currency::query()->where('code', $from)->first();
        $toCurrency   = Currency::query()->where('code', $to)->first();

        if ($fromCurrency === null || $toCurrency === null) {
            return ['from' => $from, 'to' => $to, 'range' => $range, 'points' => [], 'average' => null, 'change_percent' => null, 'current' => null];
        }

        $fromRevisions = $this->revisions($fromCurrency, $start);
        $toRevisions   = $this->revisions($toCurrency, $start);

        $moments = $fromRevisions->pluck('created_at')
            ->merge($toRevisions->pluck('created_at'))
            ->map(fn ($at) => CarbonImmutable::parse($at))
            ->filter(fn (CarbonImmutable $at) => $at->greaterThanOrEqualTo($start))
            ->push($start)
            ->push($now)
            ->unique(fn (CarbonImmutable $at) => $at->getTimestamp())
            ->sort()
            ->values();

        $points = [];
        foreach ($moments as $at) {
            $fromRate = $this->rateAt($fromCurrency, $fromRevisions, $at);
            $toRate   = $this->rateAt($toCurrency, $toRevisions, $at);

            if (bccomp($fromRate, '0', 18) <= 0 || bccomp($toRate, '0', 18) <= 0) {
                continue;
            }

            $points[] = ['at' => $at->toIso8601String(), 'rate' => DecimalMoney::rate(DecimalMoney::divide($toRate, $fromRate))];
        }

        $moved = collect($points)->pluck('rate')->unique()->count() > 1;
        if (! $moved) {
            $points = array_slice($points, -1);
        }

        $rates   = array_column($points, 'rate');
        $current = $rates === [] ? null : end($rates);
        $first   = $rates === [] ? null : $rates[0];

        return [
            'from'           => $from,
            'to'             => $to,
            'range'          => $range,
            'points'         => $points,
            'average'        => $rates === [] ? null : DecimalMoney::rate(DecimalMoney::divide(array_reduce($rates, fn (string $carry, string $rate): string => DecimalMoney::add($carry, $rate, DecimalMoney::CALCULATION_SCALE), '0'), (string) count($rates))),
            'change_percent' => $first !== null && $current !== null && bccomp($first, '0', 18) > 0 && count($rates) > 1
                ? DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide(DecimalMoney::subtract($current, $first, DecimalMoney::CALCULATION_SCALE), $first), 100), 2)
                : null,
            'current' => $current,
        ];
    }

    /**
     * Revisions inside the window, plus the one just before it so the rate
     * at the window's start is known.
     *
     * @return Collection<int, CurrencyRateRevision>
     */
    private function revisions(Currency $currency, CarbonImmutable $start): Collection
    {
        $inside = CurrencyRateRevision::query()
            ->where('currency_id', $currency->id)
            ->where('created_at', '>=', $start)
            ->orderBy('created_at')
            ->get();

        $before = CurrencyRateRevision::query()
            ->where('currency_id', $currency->id)
            ->where('created_at', '<', $start)
            ->orderByDesc('created_at')
            ->first();

        return $before === null ? $inside : $inside->prepend($before);
    }

    /**
     * @param Collection<int, CurrencyRateRevision> $revisions
     */
    private function rateAt(Currency $currency, Collection $revisions, CarbonImmutable $at): string
    {
        $last = $revisions->filter(fn (CurrencyRateRevision $revision) => CarbonImmutable::parse($revision->created_at)->lessThanOrEqualTo($at))->last();

        if ($last !== null) {
            return (string) $last->new_rate;
        }

        $first = $revisions->first();

        return $first !== null ? (string) $first->old_rate : $currency->exchange_rate;
    }
}
