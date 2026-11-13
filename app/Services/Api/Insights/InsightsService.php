<?php

declare(strict_types=1);

namespace App\Services\Api\Insights;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Models\CurrencyRateRevision;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyService;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a customer's money did over a period, restated in one currency:
 * in, out and net; the same for the five periods before; spending by
 * category; the merchants paid most. Everything is computed here from
 * their own rows, and every figure is in the display currency at the
 * period's average rate where the rate moved, else the current one.
 */
final class InsightsService
{
    public const PERIODS = ['week', 'month', 'quarter', 'year'];

    private const SERIES = 6;

    public function __construct(private readonly CurrencyService $currencies) {}

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $period, int $offset, ?CarbonImmutable $now = null): array
    {
        $now   = $now ?? CarbonImmutable::now();
        $start = match ($period) {
            'week'    => $now->startOfWeek()->addWeeks($offset),
            'quarter' => $now->startOfQuarter()->addQuarters($offset),
            'year'    => $now->startOfYear()->addYears($offset),
            default   => $now->startOfMonth()->addMonths($offset),
        };
        $end = match ($period) {
            'week'    => $start->endOfWeek(),
            'quarter' => $start->endOfQuarter(),
            'year'    => $start->endOfYear(),
            default   => $start->endOfMonth(),
        };

        return [$start, $end];
    }

    public static function label(string $period, CarbonImmutable $start): string
    {
        return match ($period) {
            'week'    => $start->format('j M'),
            'quarter' => 'Q'.$start->quarter.' '.$start->format('y'),
            'year'    => $start->format('Y'),
            default   => $start->format('M'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, string $period, int $offset, string $display): array
    {
        [$start, $end] = self::range($period, $offset);
        $buckets       = $this->buckets($period, $offset);
        $rows          = $this->rows($user, $buckets[0][0], $end);
        $convert       = $this->converter($rows, $display, $start, $end);
        $categorizer   = TransactionCategorizer::forUser((int) $user->id);

        $series = [];
        foreach ($buckets as [$bStart, $bEnd]) {
            $in  = '0';
            $out = '0';
            foreach ($rows as $row) {
                if ($row->created_at === null || $row->created_at < $bStart || $row->created_at > $bEnd) {
                    continue;
                }
                if ($row->amount_flow === AmountFlow::PLUS) {
                    $in = DecimalMoney::add($in, $convert($row), DecimalMoney::CALCULATION_SCALE);
                } elseif ($row->amount_flow === AmountFlow::MINUS) {
                    $out = DecimalMoney::add($out, $convert($row), DecimalMoney::CALCULATION_SCALE);
                }
            }
            $series[] = ['label' => self::label($period, $bStart), 'start' => $bStart->toDateString(), 'in' => ApiMoney::amount($in, $display), 'out' => ApiMoney::amount($out, $display), 'raw_in' => $in, 'raw_out' => $out];
        }

        $current  = $series[count($series) - 1];
        $previous = $series[count($series) - 2];
        $inRange  = $rows->filter(fn (Transaction $row) => $row->created_at !== null && $row->created_at >= $start && $row->created_at <= $end);

        $categories = [];
        $merchants  = [];
        $spent      = '0';
        foreach ($inRange as $row) {
            $category = $categorizer->categoryOf($row);
            if ($category === null) {
                continue;
            }
            $value = $convert($row);
            $spent = DecimalMoney::add($spent, $value, DecimalMoney::CALCULATION_SCALE);

            $categories[$category] ??= ['amount' => '0', 'count' => 0];
            $categories[$category]['amount'] = DecimalMoney::add($categories[$category]['amount'], $value, DecimalMoney::CALCULATION_SCALE);
            $categories[$category]['count']++;

            $key = TransactionCategorizer::merchantKey($row);
            if ($key !== null) {
                $merchants[$key] ??= ['name' => TransactionCategorizer::merchantName($row), 'key' => $key, 'amount' => '0', 'count' => 0, 'category' => $category];
                $merchants[$key]['amount'] = DecimalMoney::add($merchants[$key]['amount'], $value, DecimalMoney::CALCULATION_SCALE);
                $merchants[$key]['count']++;
            }
        }

        uasort($categories, fn (array $a, array $b): int => DecimalMoney::compare($b['amount'], $a['amount']));
        uasort($merchants, fn (array $a, array $b): int => DecimalMoney::compare($b['amount'], $a['amount']));

        $active = count(array_filter($series, fn (array $s): bool => DecimalMoney::compare($s['raw_in'], '0') > 0 || DecimalMoney::compare($s['raw_out'], '0') > 0));

        return [
            'period'       => $period,
            'offset'       => $offset,
            'currency'     => $display,
            'decimals'     => ApiMoney::decimals($display),
            'range'        => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'label' => self::rangeLabel($period, $start)],
            'transactions' => $inRange->count(),
            'in'           => $current['in'],
            'out'          => $current['out'],
            // Spending only: what the categories add up to. Money out also
            // counts exchanges and withdrawals, which are moves, not spend.
            'spent'      => ApiMoney::amount($spent, $display),
            'net'        => ApiMoney::amount(DecimalMoney::subtract($current['raw_in'], $current['raw_out'], DecimalMoney::CALCULATION_SCALE), $display),
            'out_change' => self::change($current['raw_out'], $previous['raw_out']),
            'series'     => array_map(fn (array $s): array => ['label' => $s['label'], 'start' => $s['start'], 'in' => $s['in'], 'out' => $s['out']], $series),
            'categories' => array_values(array_map(fn (string $k, array $c): array => [
                'key'    => $k,
                'amount' => ApiMoney::amount($c['amount'], $display),
                'count'  => $c['count'],
                'share'  => self::share($c['amount'], $spent),
            ], array_keys($categories), $categories)),
            'merchants' => array_values(array_map(fn (array $m): array => array_replace($m, ['amount' => ApiMoney::amount($m['amount'], $display)]), array_slice($merchants, 0, 5, true))),
            'ready'     => ['trend' => $active >= 2, 'categories' => $categories !== []],
        ];
    }

    /**
     * One category over the period: what it cost, how that compares, the
     * six-period trend, and the rows largest first.
     *
     * @return array<string, mixed>
     */
    public function category(User $user, string $category, string $period, int $offset, string $display): array
    {
        [$start, $end] = self::range($period, $offset);
        $buckets       = $this->buckets($period, $offset);
        $rows          = $this->rows($user, $buckets[0][0], $end);
        $convert       = $this->converter($rows, $display, $start, $end);
        $categorizer   = TransactionCategorizer::forUser((int) $user->id);

        $series   = [];
        $allSpent = '0';
        $inRange  = [];
        foreach ($buckets as $i => [$bStart, $bEnd]) {
            $sum = '0';
            foreach ($rows as $row) {
                if ($row->created_at === null || $row->created_at < $bStart || $row->created_at > $bEnd || $categorizer->categoryOf($row) === null) {
                    continue;
                }
                $value = $convert($row);
                if ($i === count($buckets) - 1) {
                    $allSpent = DecimalMoney::add($allSpent, $value, DecimalMoney::CALCULATION_SCALE);
                }
                if ($categorizer->categoryOf($row) !== $category) {
                    continue;
                }
                $sum = DecimalMoney::add($sum, $value, DecimalMoney::CALCULATION_SCALE);
                if ($i === count($buckets) - 1) {
                    $inRange[] = ['row' => $row, 'value' => $value];
                }
            }
            $series[] = ['label' => self::label($period, $bStart), 'start' => $bStart->toDateString(), 'raw' => $sum, 'amount' => ApiMoney::amount($sum, $display)];
        }

        usort($inRange, fn (array $a, array $b): int => DecimalMoney::compare($b['value'], $a['value']));

        $spent    = $series[count($series) - 1]['raw'];
        $previous = $series[count($series) - 2]['raw'];
        $total    = array_reduce($series, fn (string $carry, array $s): string => DecimalMoney::add($carry, $s['raw'], DecimalMoney::CALCULATION_SCALE), '0');
        $average  = DecimalMoney::divide($total, (string) count($series));

        return [
            'category'         => $category,
            'period'           => $period,
            'offset'           => $offset,
            'currency'         => $display,
            'decimals'         => ApiMoney::decimals($display),
            'range'            => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'label' => self::rangeLabel($period, $start)],
            'spent'            => ApiMoney::amount($spent, $display),
            'share'            => self::share($spent, $allSpent),
            'count'            => count($inRange),
            'previous'         => ApiMoney::amount($previous, $display),
            'change'           => ApiMoney::amount(DecimalMoney::subtract($spent, $previous, DecimalMoney::CALCULATION_SCALE), $display),
            'change_percent'   => self::change($spent, $previous),
            'average'          => ApiMoney::amount($average, $display),
            'suggested_budget' => ApiMoney::amount(self::roundUp($average, 50), $display),
            'series'           => array_map(fn (array $s): array => ['label' => $s['label'], 'start' => $s['start'], 'amount' => $s['amount']], $series),
            'transactions'     => array_map(fn (array $t): array => (new TransactionResource($t['row']))->resolve() + ['converted' => ApiMoney::amount($t['value'], $display)], $inRange),
        ];
    }

    /**
     * What was spent per category between two moments, in the display
     * currency - the figure a budget is measured against.
     *
     * @return array<string, array{amount: string, count: int}>
     */
    public function spendByCategory(User $user, CarbonImmutable $start, CarbonImmutable $end, string $display): array
    {
        $rows        = $this->rows($user, $start, $end);
        $convert     = $this->converter($rows, $display, $start, $end);
        $categorizer = TransactionCategorizer::forUser((int) $user->id);
        $spend       = [];

        foreach ($rows as $row) {
            $category = $categorizer->categoryOf($row);
            if ($category === null) {
                continue;
            }
            $spend[$category] ??= ['amount' => '0', 'count' => 0];
            $spend[$category]['amount'] = DecimalMoney::add($spend[$category]['amount'], $convert($row), DecimalMoney::CALCULATION_SCALE);
            $spend[$category]['count']++;
        }

        return $spend;
    }

    /**
     * The average monthly spend per category over the last N whole months,
     * in the display currency - what a first budget is suggested from.
     *
     * @return array<string, string>
     */
    public function monthlyAverageByCategory(User $user, int $months, string $display): array
    {
        $months = max(1, $months);
        $end    = CarbonImmutable::now()->startOfMonth()->subSecond();
        $start  = $end->startOfMonth()->subMonths($months - 1);
        $totals = $this->spendByCategory($user, $start, $end, $display);

        return array_map(fn (array $c): string => DecimalMoney::divide($c['amount'], (string) $months), $totals);
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function buckets(string $period, int $offset): array
    {
        $buckets = [];
        for ($i = self::SERIES - 1; $i >= 0; $i--) {
            $buckets[] = self::range($period, $offset - $i);
        }

        return $buckets;
    }

    /**
     * Completed rows whose money moved: a sandbox payment is a test, not
     * spending, so it stays out of every figure and of budgets.
     *
     * @return Collection<int, Transaction>
     */
    private function rows(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $user->transactions()
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->whereBetween('created_at', [$from, $to])
            ->get();
    }

    /**
     * A row restated in the display currency. Rates are the average of
     * what the admin's revisions recorded inside the period for currencies
     * that moved, and the current rate for the rest.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return callable(Transaction): string
     */
    private function converter(Collection $rows, string $display, CarbonImmutable $start, CarbonImmutable $end): callable
    {
        $base  = strtoupper((string) siteCurrency());
        $codes = $rows->pluck('currency')->push($display)->filter()->map(fn ($c) => strtoupper((string) $c))->unique();
        $rates = $this->currencies->ratesByCode($codes);

        $averages = CurrencyRateRevision::query()
            ->whereIn('currency_code', $codes)
            ->whereBetween('created_at', [$start, $end])
            ->get(['currency_code', 'new_rate'])
            ->groupBy(fn ($r) => strtoupper((string) $r->currency_code))
            ->map(fn ($group) => DecimalMoney::divide(
                $group->reduce(fn (string $carry, $r): string => DecimalMoney::add($carry, (string) $r->new_rate, DecimalMoney::CALCULATION_SCALE), '0'),
                (string) $group->count(),
            ));

        foreach ($averages as $code => $average) {
            if (DecimalMoney::compare($average, '0') > 0) {
                $rates[$code] = DecimalMoney::rate($average);
            }
        }

        $factor = $display === $base ? '1' : ($rates->get($display) ?? '1');

        return fn (Transaction $row): string => DecimalMoney::multiply($this->currencies->toBaseAmount($row->amount, $row->currency, $rates), $factor);
    }

    private static function share(string $part, string $whole): string
    {
        return DecimalMoney::compare($whole, '0') <= 0 ? '0' : DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide($part, $whole), '100'), 1);
    }

    /**
     * "+17" for a rise, "-8" for a fall, null when there was nothing before.
     */
    private static function change(string $now, string $before): ?string
    {
        if (DecimalMoney::compare($before, '0') <= 0) {
            return null;
        }
        $percent = DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide(DecimalMoney::subtract($now, $before, DecimalMoney::CALCULATION_SCALE), $before), '100'), 0);

        return (DecimalMoney::compare($percent, '0') >= 0 ? '+' : '').$percent;
    }

    private static function roundUp(string $amount, int $step): string
    {
        $steps = (int) ceil((float) DecimalMoney::divide($amount, (string) $step));

        return (string) ($steps * $step);
    }

    private static function rangeLabel(string $period, CarbonImmutable $start): string
    {
        return match ($period) {
            'week'    => $start->format('j M').' – '.$start->endOfWeek()->format('j M'),
            'quarter' => 'Q'.$start->quarter.' '.$start->format('Y'),
            'year'    => $start->format('Y'),
            default   => $start->format('F Y'),
        };
    }
}
