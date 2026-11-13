<?php

namespace App\Listeners;

use App\Models\BackgroundTaskLog;
use App\Services\BackgroundTaskRegistry;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Throwable;

/**
 * Put the scheduler's own runs into the Background Tasks panel.
 *
 * Every row on that screen read "Never run" on servers whose cron was
 * working perfectly, because the only thing that ever wrote a
 * BackgroundTaskLog was BackgroundTaskRunner, and the only caller of the
 * runner is the admin controller behind the manual Run button. The schedule
 * in bootstrap/app.php calls `$schedule->command(...)` straight through
 * Laravel, which never touches the log at all. `trigger_type` has always
 * carried a hardcoded 'manual' with nothing on the other side of it.
 *
 * An operator reading "Never run" concluded background processing was dead
 * and went looking for a cron fault that did not exist.
 */
class RecordScheduledTaskRun
{
    /*
     * The methods below are deliberately not called `handle*`.
     *
     * Application::configure() calls withEvents(), which turns on listener
     * discovery over app/Listeners, and DiscoverEvents claims every public
     * method whose name starts with `handle` or is `__invoke`. Named that way
     * these would be registered twice - once by discovery, once by the
     * explicit Event::listen in AppServiceProvider - and every scheduled run
     * would write two rows. Measured: the dispatcher held two listeners and
     * one tick logged twice.
     */
    public function __construct(private BackgroundTaskRegistry $registry) {}

    /**
     * Fired whether or not the command succeeded. The scheduler spawns
     * `php artisan …` as a child process and dispatches this once the process
     * returns; a command that threw inside that process exits 1 and arrives
     * here, not at whenFailed — which only sees an exception raised while
     * *starting* the process. Recording every arrival as a success painted a
     * command green on the very tick it was throwing.
     *
     * A null exit code is an event that finished without reporting one
     * (`runInBackground`, whose code lands later through schedule:finish).
     * Nothing on this schedule runs that way, and null reads as success
     * rather than inventing a failure the scheduler did not report.
     */
    public function whenFinished(ScheduledTaskFinished $event): void
    {
        $exitCode = $event->task->exitCode;
        $failed   = $exitCode !== null && (int) $exitCode !== 0;

        $this->record(
            $event->task->command ?? null,
            $failed ? 'failed' : 'success',
            (int) round($event->runtime * 1000),
            $failed ? __('Command failed with exit code :code.', ['code' => (int) $exitCode]) : null,
        );
    }

    public function whenFailed(ScheduledTaskFailed $event): void
    {
        $this->record(
            $event->task->command ?? null,
            'failed',
            null,
            $event->exception->getMessage(),
        );
    }

    /**
     * A scheduler tick must never be brought down by its own bookkeeping. The
     * commands on this schedule expire P2P orders, dispatch remittances and
     * refresh rates; losing one because a log row could not be written would
     * trade a cosmetic problem for a financial one.
     */
    private function record(?string $command, string $status, ?int $durationMs, ?string $error = null): void
    {
        try {
            $key = $this->registry->keyForCommand($command);

            if ($key === null) {
                return;
            }

            $finishedAt = now();

            BackgroundTaskLog::query()->create([
                'task_key'          => $key,
                'command_signature' => $this->registry->get($key)['signature'],
                'status'            => $status,
                'started_at'        => $durationMs === null
                    ? $finishedAt
                    : $finishedAt->copy()->subMilliseconds($durationMs),
                'finished_at'   => $finishedAt,
                'duration_ms'   => $durationMs,
                'error_message' => $error,
                // Nobody pressed anything. Left null so the history can tell a
                // cron tick apart from an administrator's manual run.
                'executed_by'  => null,
                'trigger_type' => 'scheduled',
            ]);
        } catch (Throwable) {
            // Deliberately silent: see the note above.
        }
    }
}
