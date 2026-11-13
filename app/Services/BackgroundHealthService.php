<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is background processing actually running on this server?
 *
 * Almost every "the email never arrived" report on this application comes
 * down to the same thing: queued work is written but nothing drains it, and
 * scheduled commands never fire. Both fail silently — no exception, no log
 * line, nothing an administrator can see. The dashboard notice used to be a
 * static banner saying "set these up", which an admin dismisses once and
 * never sees again, whether or not they actually did it.
 *
 * This answers the question from real state instead:
 *
 *   - The scheduler writes a heartbeat every minute. A stale (or absent)
 *     heartbeat means cron is not calling `schedule:run`.
 *   - The queue is measured by its own backlog: a job that has been due for
 *     longer than the grace window is a job no worker picked up.
 *
 * Both checks fail *soft*. A driver this cannot inspect (redis, sqs) or a
 * database it cannot reach reports `unknown`, never a false alarm — telling
 * an administrator their queue is broken when it is fine is worse than
 * saying nothing.
 */
class BackgroundHealthService
{
    /**
     * Where the scheduler records that it ran. Written by the per-minute
     * heartbeat registered in bootstrap/app.php.
     */
    public const HEARTBEAT_CACHE_KEY = 'background.scheduler_last_run_at';

    public const STATUS_OK = 'ok';

    public const STATUS_STALLED = 'stalled';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Minutes a heartbeat may age before cron is considered dead. The
     * scheduler ticks every minute; five tolerates a slow or briefly
     * contended server without crying wolf.
     *
     * Public so the module's attention bar states the window it measures.
     * It used to quote ten minutes of its own, which was not this number.
     */
    public const SCHEDULER_GRACE_MINUTES = 5;

    /**
     * Minutes a due job may wait before the worker is considered dead.
     * Generous on purpose: a cPanel install often drains the queue from a
     * once-a-minute cron, and a long job can hold the worker meanwhile.
     */
    private const QUEUE_GRACE_MINUTES = 15;

    /**
     * Record that the scheduler ran. Called once a minute by the schedule.
     */
    public function recordSchedulerHeartbeat(): void
    {
        try {
            Cache::forever(self::HEARTBEAT_CACHE_KEY, now()->toIso8601String());
        } catch (Throwable) {
            // A cache store that cannot be written is its own problem; it
            // must never take the scheduler tick down with it.
        }
    }

    /**
     * @return array{status: string, last_run_at: ?CarbonInterface, minutes_ago: ?int}
     */
    public function scheduler(): array
    {
        $lastRun = $this->lastSchedulerRun();

        if ($lastRun === null) {
            return [
                'status'      => self::STATUS_UNKNOWN,
                'last_run_at' => null,
                'minutes_ago' => null,
            ];
        }

        $minutesAgo = (int) $lastRun->diffInMinutes(now());

        return [
            'status'      => $minutesAgo > self::SCHEDULER_GRACE_MINUTES ? self::STATUS_STALLED : self::STATUS_OK,
            'last_run_at' => $lastRun,
            'minutes_ago' => $minutesAgo,
        ];
    }

    /**
     * @return array{status: string, pending: ?int, oldest_minutes: ?int, failed: ?int, driver: string}
     */
    public function queue(): array
    {
        $driver = (string) config('queue.default');

        // The failed table is written by the `failed` driver, configured
        // separately from the connection jobs are pushed to. A backlog this
        // cannot measure therefore says nothing about whether dead jobs are
        // readable — count them on every driver, `sync` included, or an
        // installation running jobs inline reports no failures while hundreds
        // sit in the table.
        $failed = $this->failedJobCount();

        $unknown = [
            'status'         => self::STATUS_UNKNOWN,
            'pending'        => null,
            'oldest_minutes' => null,
            'failed'         => $failed,
            'driver'         => $driver,
        ];

        // `sync` runs jobs inline, so there is no backlog to measure and
        // nothing is ever waiting — reporting a problem would be wrong.
        if ($driver === 'sync') {
            return array_merge($unknown, ['status' => self::STATUS_OK]);
        }

        // Only the database driver keeps its backlog somewhere this can read.
        if ($driver !== 'database') {
            return $unknown;
        }

        try {
            $due = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp());

            $pending      = (int) (clone $due)->count();
            $oldestDueAt  = (clone $due)->min('available_at');
            $oldestMinute = $oldestDueAt === null
                ? 0
                : (int) Carbon::createFromTimestamp((int) $oldestDueAt)->diffInMinutes(now());
        } catch (Throwable) {
            return $unknown;
        }

        return [
            'status'         => $oldestMinute > self::QUEUE_GRACE_MINUTES ? self::STATUS_STALLED : self::STATUS_OK,
            'pending'        => $pending,
            'oldest_minutes' => $oldestMinute,
            'failed'         => $failed,
            'driver'         => $driver,
        ];
    }

    /**
     * Every job the queue has given up on, or null when the table cannot be
     * read. Null and zero are different answers, and a caller that collapses
     * them tells an administrator nothing has failed when it simply could not
     * look.
     */
    private function failedJobCount(): ?int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The whole picture, plus whether an administrator needs to act.
     *
     * `needs_attention` is deliberately true only for a *confirmed* stall.
     * An `unknown` on either side stays quiet.
     *
     * @return array{scheduler: array<string, mixed>, queue: array<string, mixed>, needs_attention: bool}
     */
    public function summary(): array
    {
        $scheduler = $this->scheduler();
        $queue     = $this->queue();

        return [
            'scheduler'       => $scheduler,
            'queue'           => $queue,
            'needs_attention' => $scheduler['status'] === self::STATUS_STALLED
                || $queue['status']                   === self::STATUS_STALLED,
        ];
    }

    private function lastSchedulerRun(): ?CarbonInterface
    {
        try {
            $stored = Cache::get(self::HEARTBEAT_CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Carbon::parse($stored);
        } catch (Throwable) {
            return null;
        }
    }
}
