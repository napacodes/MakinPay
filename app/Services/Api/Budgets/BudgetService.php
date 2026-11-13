<?php

declare(strict_types=1);

namespace App\Services\Api\Budgets;

use App\Models\Budget;
use App\Models\User;
use App\Services\Api\Insights\InsightsService;
use App\Services\Api\Insights\TransactionCategorizer;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

/**
 * Budgets measured against what Insights says was spent. A budget is kept
 * in the customer's display currency; spending in any other currency is
 * converted the way Insights converts it, so the two screens agree.
 */
class BudgetService
{
    public const WARNING_SHARE = 60;

    public const DANGER_SHARE = 90;

    /**
     * @var array<string, array<string, array{amount: string, count: int}>>
     */
    private array $spendCache = [];

    public function __construct(private readonly InsightsService $insights) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, int $offset = 0): array
    {
        $display = $user->displayCurrency();
        $now     = CarbonImmutable::now();
        $items   = $user->budgets()->orderBy('category')->get()->map(fn (Budget $b): array => $this->describe($user, $b, $display, $offset, $now))->values()->all();

        $limit = '0';
        $spent = '0';
        foreach ($items as $item) {
            $limit = DecimalMoney::add($limit, $item['raw_limit'], DecimalMoney::CALCULATION_SCALE);
            $spent = DecimalMoney::add($spent, $item['raw_spent'], DecimalMoney::CALCULATION_SCALE);
        }
        $month    = $now->startOfMonth()->addMonths($offset);
        $daysLeft = $offset === 0 ? max(0, (int) $now->startOfDay()->diffInDays($month->endOfMonth()->startOfDay(), false)) + 1 : 0;
        $elapsed  = $offset < 0 ? 100 : ($offset > 0 ? 0 : (int) round($now->day / $month->daysInMonth * 100));
        $left     = DecimalMoney::subtract($limit, $spent, DecimalMoney::CALCULATION_SCALE);
        $ratio    = self::ratio($spent, $limit);
        $averages = $this->insights->monthlyAverageByCategory($user, 3, $display);

        return [
            'currency' => $display,
            'decimals' => ApiMoney::decimals($display),
            'month'    => ['label' => $month->format('M Y'), 'short' => $month->format('M'), 'offset' => $offset],
            'hero'     => $items === [] ? null : [
                'limit'     => ApiMoney::amount($limit, $display),
                'spent'     => ApiMoney::amount($spent, $display),
                'left'      => ApiMoney::amount(DecimalMoney::compare($left, '0') < 0 ? '0' : $left, $display),
                'over'      => DecimalMoney::compare($left, '0') < 0 ? ApiMoney::amount(DecimalMoney::multiply($left, '-1'), $display) : null,
                'ratio'     => $ratio,
                'days_left' => $daysLeft,
                'per_day'   => $daysLeft > 0 && DecimalMoney::compare($left, '0') > 0 ? ApiMoney::amount(DecimalMoney::divide($left, (string) $daysLeft), $display) : null,
                'on_track'  => $ratio <= $elapsed + 5,
                'status'    => self::statusOf($ratio, self::DANGER_SHARE),
            ],
            'budgets'     => array_map(fn (array $i): array => array_diff_key($i, ['raw_limit' => 1, 'raw_spent' => 1]), $items),
            'suggestions' => $this->suggestions($averages, array_column($items, 'category'), $display),
            'averages'    => array_map(fn (string $a): string => ApiMoney::amount($a, $display), $averages),
            'categories'  => TransactionCategorizer::ALL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $user, Budget $budget, string $display, int $offset = 0, ?CarbonImmutable $now = null): array
    {
        $now           = $now ?? CarbonImmutable::now();
        [$start, $end] = $budget->range($offset, $now);
        $current       = $this->spend($user, $start, $end, $display)[$budget->category] ?? ['amount' => '0', 'count' => 0];
        $carried       = '0';
        if ($budget->rollover && $budget->period !== 'custom') {
            [$pStart, $pEnd] = $budget->range($offset - 1, $now);
            $previous        = $this->spend($user, $pStart, $pEnd, $display)[$budget->category]['amount'] ?? '0';
            $carried         = DecimalMoney::subtract((string) $budget->amount, $previous, DecimalMoney::CALCULATION_SCALE);
            $carried         = DecimalMoney::compare($carried, DecimalMoney::multiply((string) $budget->amount, '-1')) < 0 ? DecimalMoney::multiply((string) $budget->amount, '-1') : $carried;
        }
        $limit    = DecimalMoney::add((string) $budget->amount, $carried, DecimalMoney::CALCULATION_SCALE);
        $limit    = DecimalMoney::compare($limit, '0') < 0 ? '0' : $limit;
        $spent    = $current['amount'];
        $left     = DecimalMoney::subtract($limit, $spent, DecimalMoney::CALCULATION_SCALE);
        $ratio    = self::ratio($spent, $limit);
        $daysLeft = $offset === 0 && $end >= $now ? max(0, (int) $now->startOfDay()->diffInDays($end->startOfDay(), false)) + 1 : 0;

        return [
            'id'              => $budget->id,
            'category'        => $budget->category,
            'amount'          => ApiMoney::amount((string) $budget->amount, $display),
            'carried'         => ApiMoney::amount($carried, $display),
            'limit'           => ApiMoney::amount($limit, $display),
            'spent'           => ApiMoney::amount($spent, $display),
            'left'            => ApiMoney::amount(DecimalMoney::compare($left, '0') < 0 ? '0' : $left, $display),
            'over'            => DecimalMoney::compare($left, '0') < 0 ? ApiMoney::amount(DecimalMoney::multiply($left, '-1'), $display) : null,
            'ratio'           => $ratio,
            'count'           => $current['count'],
            'status'          => self::statusOf($ratio, $budget->alert_threshold),
            'period'          => $budget->period,
            'alert_threshold' => $budget->alert_threshold,
            'rollover'        => $budget->rollover,
            'currency'        => $display,
            'range'           => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'days_left'       => $daysLeft,
            'raw_limit'       => $limit,
            'raw_spent'       => $spent,
        ];
    }

    /**
     * Where a first budget could start: the categories without one, at
     * their three-month average rounded up to a round figure.
     *
     * @param  array<string, string>                                      $averages
     * @param  list<string>                                               $taken
     * @return list<array{category: string, amount: string, months: int}>
     */
    public function suggestions(array $averages, array $taken, string $display): array
    {
        $out = [];
        foreach ($averages as $category => $average) {
            if (in_array($category, $taken, true) || DecimalMoney::compare($average, '0') <= 0) {
                continue;
            }
            $out[] = ['category' => $category, 'amount' => ApiMoney::amount(self::roundUp($average, 10), $display), 'months' => 3, 'raw' => $average];
        }
        usort($out, fn (array $a, array $b): int => DecimalMoney::compare($b['raw'], $a['raw']));

        return array_map(fn (array $s): array => array_diff_key($s, ['raw' => 1]), array_slice($out, 0, 4));
    }

    /**
     * The alert level a budget has reached this period: 100 when over, its
     * own threshold when past that, null while under.
     */
    public static function alertLevel(int $ratio, int $threshold): ?int
    {
        if ($ratio >= 100) {
            return 100;
        }

        return $ratio >= $threshold ? $threshold : null;
    }

    public static function statusOf(int $ratio, int $threshold): string
    {
        if ($ratio >= 100) {
            return 'over';
        }

        return $ratio >= min($threshold, self::DANGER_SHARE) ? 'warning' : 'ok';
    }

    /**
     * @return array<string, array{amount: string, count: int}>
     */
    private function spend(User $user, CarbonImmutable $start, CarbonImmutable $end, string $display): array
    {
        $key = $user->id.'|'.$start->toIso8601String().'|'.$end->toIso8601String().'|'.$display;

        return $this->spendCache[$key] ??= $this->insights->spendByCategory($user, $start, $end, $display);
    }

    private static function ratio(string $spent, string $limit): int
    {
        if (DecimalMoney::compare($limit, '0') <= 0) {
            return DecimalMoney::compare($spent, '0') > 0 ? 100 : 0;
        }

        return (int) DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide($spent, $limit), '100'), 0);
    }

    private static function roundUp(string $value, int $step): string
    {
        $units   = DecimalMoney::divide($value, (string) $step);
        $whole   = (int) explode('.', $units)[0];
        $rounded = DecimalMoney::compare($units, (string) $whole) > 0 ? $whole + 1 : $whole;

        return DecimalMoney::multiply((string) max(1, $rounded), (string) $step);
    }
}
