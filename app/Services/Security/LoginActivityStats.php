<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\LoginRisk;
use App\Models\LoginActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The four numbers across the top of the Activity history screen.
 *
 * Cached for a minute. The page is read by someone watching a live incident,
 * so the window is short, but four grouped counts over the sign-in table is
 * not something to run again for every sorted column and every page of
 * results.
 */
class LoginActivityStats
{
    public const CACHE_KEY = 'login-activity:stats';

    public const CACHE_SECONDS = 60;

    /**
     * How many hourly buckets the sparklines draw.
     */
    private const SPARK_HOURS = 7;

    /**
     * @return list<array<string, mixed>>
     */
    public function headerStats(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->compute());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * How many flagged sign-ins today — the header's status pill.
     */
    public function flaggedToday(): int
    {
        return LoginActivity::query()
            ->succeeded()
            ->forUsers()
            ->flagged()
            ->where('login_at', '>=', Carbon::today())
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compute(): array
    {
        $today     = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $signInsToday     = $this->arrivals($today, $today->copy()->endOfDay());
        $signInsYesterday = $this->arrivals($yesterday, $yesterday->copy()->endOfDay());

        $accountsToday     = $this->accounts($today, $today->copy()->endOfDay());
        $accountsYesterday = $this->accounts($yesterday, $yesterday->copy()->endOfDay());

        $flagged = $this->flaggedToday();
        $blocked = LoginActivity::query()
            ->succeeded()
            ->forUsers()
            ->risk(LoginRisk::BLOCKED)
            ->where('login_at', '>=', $today)
            ->count();

        $failedToday     = $this->failed($today, $today->copy()->endOfDay());
        $failedYesterday = $this->failed($yesterday, $yesterday->copy()->endOfDay());
        $lockedAccounts  = $this->lockedAccounts($today);

        return [
            [
                'label' => __('Sign-ins today'),
                'value' => number_format($signInsToday),
                'delta' => $this->percentDelta($signInsToday, $signInsYesterday),
                'tone'  => $signInsToday >= $signInsYesterday ? 'success' : 'neutral',
                'note'  => __('vs. yesterday'),
                'spark' => $this->hourlySpark(),
            ],
            [
                'label' => __('Unique accounts'),
                'value' => number_format($accountsToday),
                'delta' => $this->percentDelta($accountsToday, $accountsYesterday),
                'tone'  => $accountsToday >= $accountsYesterday ? 'success' : 'neutral',
                'note'  => __('last 24 h'),
                'spark' => $this->hourlySpark(true),
            ],
            [
                'label' => __('Flagged'),
                'value' => number_format($flagged),
                'delta' => $blocked > 0 ? trans_choice('{1} :count blocked|[2,*] :count blocked', $blocked, ['count' => $blocked]) : null,
                'tone'  => $flagged > 0 ? 'warning' : 'success',
                'note'  => $flagged > 0 ? __('needs review') : __('nothing to review'),
                'icon'  => 'shield-ban',
            ],
            [
                'label' => __('Failed attempts'),
                'value' => number_format($failedToday),
                'delta' => $this->countDelta($failedToday, $failedYesterday),
                'tone'  => $failedToday <= $failedYesterday ? 'success' : 'warning',
                'note'  => $lockedAccounts > 0
                    ? trans_choice('{1} :count account locked|[2,*] :count accounts locked', $lockedAccounts, ['count' => $lockedAccounts])
                    : __('no accounts locked'),
                'icon' => 'lock',
            ],
        ];
    }

    private function arrivals(Carbon $from, Carbon $to): int
    {
        return LoginActivity::query()->succeeded()->forUsers()->whereBetween('login_at', [$from, $to])->count();
    }

    private function accounts(Carbon $from, Carbon $to): int
    {
        return (int) LoginActivity::query()
            ->succeeded()
            ->forUsers()
            ->whereBetween('login_at', [$from, $to])
            ->distinct()
            ->count('user_id');
    }

    private function failed(Carbon $from, Carbon $to): int
    {
        return LoginActivity::query()->failed()->whereBetween('login_at', [$from, $to])->count();
    }

    /**
     * Accounts that were guessed at enough times today to trip the threshold.
     */
    private function lockedAccounts(Carbon $since): int
    {
        $threshold = (int) setting('login_risk_failed_threshold', LoginRiskEvaluator::DEFAULT_FAILED_THRESHOLD);

        return LoginActivity::query()
            ->failed()
            ->whereNotNull('user_id')
            ->where('login_at', '>=', $since)
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [max(1, $threshold)])
            ->get(['user_id'])
            ->count();
    }

    /**
     * Sign-ins per hour over the last seven hours, oldest first.
     *
     * @return list<int>
     */
    private function hourlySpark(bool $unique = false): array
    {
        $now  = Carbon::now();
        $rows = LoginActivity::query()
            ->succeeded()
            ->forUsers()
            ->where('login_at', '>=', $now->copy()->subHours(self::SPARK_HOURS)->startOfHour())
            ->get(['user_id', 'login_at']);

        $spark = [];

        for ($step = self::SPARK_HOURS - 1; $step >= 0; $step--) {
            $open  = $now->copy()->subHours($step)->startOfHour();
            $close = $open->copy()->endOfHour();

            $bucket = $rows->filter(
                fn (LoginActivity $row): bool => $row->login_at !== null && $row->login_at->between($open, $close)
            );

            $spark[] = $unique ? $bucket->pluck('user_id')->unique()->count() : $bucket->count();
        }

        return $spark;
    }

    private function percentDelta(int $now, int $before): ?string
    {
        if ($before === 0) {
            return $now === 0 ? null : '+100%';
        }

        $change = (int) round((($now - $before) / $before) * 100);

        return ($change >= 0 ? '+' : '−').abs($change).'%';
    }

    private function countDelta(int $now, int $before): ?string
    {
        $change = $now - $before;

        if ($change === 0) {
            return null;
        }

        return ($change > 0 ? '+' : '−').number_format(abs($change));
    }
}
