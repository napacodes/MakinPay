<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\BackgroundTaskLog;
use App\Services\BackgroundHealthService;
use App\Services\BackgroundTaskRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The four figures the Background tasks header states, and its status pill.
 *
 * Measuring costs several aggregates and a walk of the schedule, on a header
 * that renders on all four tabs of the module, so the measurements are cached
 * for a minute. What is cached is **numbers only**: every string is built per
 * request in the reader's own locale, because a cache warmed by an Arabic
 * session would otherwise hand its Arabic copy to the next English one for
 * the rest of the minute.
 *
 * Two figures a header like this usually wants cannot be measured here, and
 * are not faked. Nothing samples queue depth over time — `jobs` rows are
 * deleted as they run — so Queued states its live figure with a tonal tile
 * rather than a sparkline and a delta drawn from history that does not
 * exist. Throughput does have history, in `background_task_logs`, so that is
 * the one cell carrying a trend.
 */
final class BackgroundTaskStats
{
    /**
     * Public because the endpoints that change these figures forget it: a
     * flush or a retry that left the header quoting the old count for up to
     * a minute would read as the action having failed.
     */
    public const CACHE_KEY = 'admin.background-tasks.header-stats';

    private const CACHE_SECONDS = 60;

    /** Hours the throughput sparkline plots, oldest first. */
    private const SPARK_HOURS = 7;

    public function __construct(
        private BackgroundHealthService $health,
        private BackgroundTaskRegistry $registry,
    ) {}

    /**
     * The four cells, in the order the handoff fixes them.
     *
     * @return list<array<string, mixed>>
     */
    public function cells(): array
    {
        $measured = $this->measurements();

        return [
            $this->queuedCell($measured['queue']),
            $this->failedCell($measured['failed']),
            $this->throughputCell($measured['throughput']),
            $this->nextScheduledCell($measured['next']),
        ];
    }

    /**
     * One pill, and it never claims more than the platform can see.
     *
     * There is no worker registry to count, so "1 worker down" has no honest
     * source. What this can say is whether the queue is draining, whether
     * cron has ticked, and whether either is measurable at all.
     *
     * @return array{tone: string, label: string}
     */
    public function status(): array
    {
        $measured  = $this->measurements();
        $queue     = $measured['queue'];
        $scheduler = $measured['scheduler'];

        return match (true) {
            $queue['status']     === BackgroundHealthService::STATUS_STALLED => ['tone' => 'danger', 'label' => __('Queue not draining')],
            $scheduler['status'] === BackgroundHealthService::STATUS_STALLED => ['tone' => 'warning', 'label' => __('Scheduler stalled')],
            $scheduler['status'] !== BackgroundHealthService::STATUS_OK      => ['tone' => 'warning', 'label' => __('Scheduler not running')],
            $queue['status'] === BackgroundHealthService::STATUS_UNKNOWN     => ['tone' => 'neutral', 'label' => __('Workers not measurable')],
            default                                                          => ['tone' => 'success', 'label' => __('Workers healthy')],
        };
    }

    /**
     * Dead jobs waiting for a decision. Zero when the table cannot be read,
     * so a caller that only asks "is there anything to retry?" gets the safe
     * answer rather than enabling a button over nothing.
     */
    public function failedCount(): int
    {
        return (int) ($this->measurements()['failed']['total'] ?? 0);
    }

    /**
     * True only for a confirmed problem, which is the health service's own
     * rule: an `unknown` on either side stays quiet.
     */
    public function needsAttention(): bool
    {
        $measured = $this->measurements();

        return $this->failedCount() > 0
            || $measured['queue']['status']     === BackgroundHealthService::STATUS_STALLED
            || $measured['scheduler']['status'] === BackgroundHealthService::STATUS_STALLED;
    }

    /**
     * Cron has not ticked inside the grace window. Separate from
     * `needsAttention()` because the attention bar has to name which of the
     * two problems it found before it can offer the right way out of it.
     */
    public function schedulerStalled(): bool
    {
        return $this->measurements()['scheduler']['status'] === BackgroundHealthService::STATUS_STALLED;
    }

    /**
     * Drop the cached measurements, for an action that has just changed them.
     */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            /* A cache store that cannot be written must not take a retry down. */
        }
    }

    /**
     * @param  array{pending: ?int, status: string, driver: string} $queue
     * @return array<string, mixed>
     */
    private function queuedCell(array $queue): array
    {
        $driver = $queue['driver'];

        /*
         * `sync` reports a null backlog because there is no queue to read,
         * not because the reading failed — jobs run inside the request, so
         * the honest figure is zero. Printing an em dash there suggested the
         * panel could not tell, which is the opposite of what it knows.
         */
        $pending    = $driver === 'sync' ? 0 : $queue['pending'];
        $measurable = $pending !== null;

        /*
         * The three-way answer this module has always given, stated in the
         * note. `sync` has no worker at all and `redis`/`sqs` keep their
         * backlog somewhere this cannot read — collapsing either into "not
         * draining" is the one false alarm the health service exists to
         * avoid raising.
         */
        $note = match (true) {
            $driver === 'sync'                                           => __('runs inline · :driver driver', ['driver' => $driver]),
            ! $measurable                                                => __('not measurable · :driver driver', ['driver' => $driver]),
            $queue['status'] === BackgroundHealthService::STATUS_STALLED => __('backing up · :driver driver', ['driver' => $driver]),
            default                                                      => __('draining · :driver driver', ['driver' => $driver]),
        };

        return [
            'label' => __('Queued'),
            'value' => $measurable ? (int) $pending : '—',
            'tone'  => $queue['status'] === BackgroundHealthService::STATUS_STALLED ? 'warning' : 'neutral',
            'note'  => $note,
            'icon'  => 'layer',
        ];
    }

    /**
     * @param  array{total: ?int, last_hour: int} $failed
     * @return array<string, mixed>
     */
    private function failedCell(array $failed): array
    {
        $total    = $failed['total'];
        $lastHour = $failed['last_hour'];

        return [
            'label' => __('Failed'),
            'value' => $total === null ? '—' : $total,
            'delta' => $lastHour     > 0 ? '+'.$lastHour : null,
            'tone'  => ($total ?? 0) > 0 ? 'danger' : 'neutral',
            'note'  => ($total ?? 0) > 0 ? __('last hour · needs retry') : __('none recorded'),
            'icon'  => 'shield-ban',
        ];
    }

    /**
     * @param  array{last_hour: int, same_hour_yesterday: int, spark: list<int>} $throughput
     * @return array<string, mixed>
     */
    private function throughputCell(array $throughput): array
    {
        $now       = $throughput['last_hour'];
        $yesterday = $throughput['same_hour_yesterday'];

        /*
         * A percentage against zero has nothing to report, so the cell drops
         * the delta rather than printing an infinity dressed as "+100%".
         */
        $percent = $yesterday > 0 ? (int) round(($now - $yesterday) / $yesterday * 100) : null;

        return [
            'label' => __('Throughput'),
            'value' => __(':n/h', ['n' => self::abbreviate($now)]),
            'delta' => $percent === null ? null : ($percent < 0 ? '−' : '+').abs($percent).'%',
            'tone'  => $percent === null || $percent >= 0 ? 'success' : 'warning',
            'note'  => __('vs. yesterday'),
            'spark' => $throughput['spark'],
            /*
             * Both, on purpose. The component drops the icon whenever the
             * sparkline has data, and keeps it when the line would be flat
             * at zero — which is every fresh installation, where a cell with
             * neither reads as half-drawn beside its three neighbours.
             */
            'icon' => 'apps-1',
        ];
    }

    /**
     * @param  array{at: ?string, label: ?string} $next
     * @return array<string, mixed>
     */
    private function nextScheduledCell(array $next): array
    {
        if ($next['at'] === null) {
            return [
                'label' => __('Next scheduled'),
                'value' => '—',
                'tone'  => 'neutral',
                'note'  => __('nothing on the schedule'),
                'icon'  => 'money-cog',
            ];
        }

        $at      = CarbonImmutable::parse($next['at'])->timezone(self::timezone());
        $minutes = (int) round(now()->diffInMinutes($at, false));

        return [
            'label' => __('Next scheduled'),
            'value' => $at->format('H:i'),
            'delta' => match (true) {
                $minutes <= 0   => __('due now'),
                $minutes < 60   => __('in :n min', ['n' => $minutes]),
                $minutes < 1440 => __('in :nh', ['n' => intdiv($minutes, 60)]),
                default         => __('in :nd', ['n' => intdiv($minutes, 1440)]),
            },
            'tone' => 'brand',
            'note' => $next['label'] === null ? __('Scheduled command') : __($next['label']),
            'icon' => 'money-cog',
        ];
    }

    /**
     * Numbers only, and not one translated string inside.
     *
     * @return array<string, mixed>
     */
    private function measurements(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->measure());
        } catch (Throwable) {
            /* An unreadable cache store is not worth a 500 on every tab. */
            return $this->measure();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(): array
    {
        $summary = $this->health->summary();
        $queue   = $summary['queue'];

        return [
            'queue' => [
                'pending' => $queue['pending'],
                'status'  => $queue['status'],
                'driver'  => $queue['driver'],
            ],
            'scheduler'  => ['status' => $summary['scheduler']['status']],
            'failed'     => ['total' => $queue['failed'], 'last_hour' => $this->failedInTheLastHour()],
            'throughput' => $this->throughput(),
            'next'       => $this->nextScheduled(),
        ];
    }

    private function failedInTheLastHour(): int
    {
        try {
            return (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Runs that finished, bucketed by the hour they started in.
     *
     * The rows are bucketed in PHP rather than by a driver-specific date
     * function, so this reads the same on MySQL and on the SQLite the test
     * suite runs.
     *
     * @return array{last_hour: int, same_hour_yesterday: int, spark: list<int>}
     */
    private function throughput(): array
    {
        $now            = CarbonImmutable::now();
        $sparkFrom      = $now->subHours(self::SPARK_HOURS);
        $yesterdayFrom  = $now->subDay()->subHour();
        $yesterdayUntil = $now->subDay();

        try {
            $started = BackgroundTaskLog::query()
                ->where('status', '!=', 'running')
                ->whereNotNull('started_at')
                ->where(function ($query) use ($sparkFrom, $yesterdayFrom, $yesterdayUntil): void {
                    $query
                        ->where('started_at', '>=', $sparkFrom)
                        ->orWhereBetween('started_at', [$yesterdayFrom, $yesterdayUntil]);
                })
                ->pluck('started_at');
        } catch (Throwable) {
            return ['last_hour' => 0, 'same_hour_yesterday' => 0, 'spark' => []];
        }

        $spark     = array_fill(0, self::SPARK_HOURS, 0);
        $yesterday = 0;

        foreach ($started as $startedAt) {
            $at = CarbonImmutable::parse($startedAt);

            if ($at->greaterThanOrEqualTo($yesterdayFrom) && $at->lessThan($yesterdayUntil)) {
                $yesterday++;

                continue;
            }

            /* Bucket 0 is the oldest of the seven, bucket 6 the hour just gone. */
            $bucket = self::SPARK_HOURS - 1 - (int) floor($at->diffInMinutes($now) / 60);

            if ($bucket >= 0 && $bucket < self::SPARK_HOURS) {
                $spark[$bucket]++;
            }
        }

        return [
            'last_hour'           => $spark[self::SPARK_HOURS - 1],
            'same_hour_yesterday' => $yesterday,
            'spark'               => array_values($spark),
        ];
    }

    /**
     * The soonest thing cron will run, and what it is called.
     *
     * The schedule is the only place that knows when; the registry is what
     * turns a command signature back into the name this module shows
     * everywhere else, so the cell and the Commands table cannot disagree.
     *
     * @return array{at: ?string, label: ?string}
     */
    private function nextScheduled(): array
    {
        $soonest = null;
        $label   = null;

        try {
            /* Registers the application's commands, or the schedule is empty. */
            app(ConsoleKernel::class)->all();

            foreach (app(Schedule::class)->events() as $event) {
                $key = $this->registry->keyForCommand($event->command ?? '');

                /*
                 * Only the commands this module manages. The schedule also
                 * carries the scheduler's own heartbeat, which runs every
                 * minute and would win every time — naming the next run
                 * "Scheduled command" on a screen whose whole subject is the
                 * ten commands listed one section below it.
                 */
                if ($key === null) {
                    continue;
                }

                $runsAt = CarbonImmutable::instance($event->nextRunDate());

                if ($soonest !== null && $runsAt->greaterThanOrEqualTo($soonest)) {
                    continue;
                }

                $task    = $this->registry->all()[$key] ?? null;
                $soonest = $runsAt;
                $label   = is_array($task) ? ($task['label'] ?? null) : null;
            }
        } catch (Throwable) {
            return ['at' => null, 'label' => null];
        }

        return [
            'at'    => $soonest?->toIso8601String(),
            'label' => $label,
        ];
    }

    /**
     * 940 → "940", 1,860 → "1.9k". The unit is added by the caller, which is
     * where the translated ":n/h" lives.
     */
    private static function abbreviate(int $value): string
    {
        if ($value < 1000) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.').'k';
    }

    private static function timezone(): string
    {
        return (string) (setting('site_timezone', config('app.timezone')) ?: config('app.timezone', 'UTC'));
    }
}
