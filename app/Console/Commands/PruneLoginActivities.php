<?php

namespace App\Console\Commands;

use App\Models\LoginActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly retention pruning for the sign-in log.
 *
 * Deleted in bounded batches: `login_activities` gains a row for every
 * arrival and every refusal on the platform, so it is the one table where an
 * unbounded DELETE at the end of a long retention change would hold a lock
 * across the whole sign-in path. Whatever the ceiling leaves behind is
 * cleared by the next night's run.
 */
class PruneLoginActivities extends Command
{
    protected $signature = 'login-activities:prune {--days= : Override the configured retention window} {--limit=0 : Stop after this many rows; 0 runs until the window is clear}';

    protected $description = 'Remove sign-in history past the configured retention window';

    /** Rows per DELETE, small enough that the lock is never held long. */
    private const BATCH = 500;

    public const DEFAULT_DAYS = 180;

    public function handle(): int
    {
        if (! Schema::hasTable('login_activities')) {
            $this->components->warn('The sign-in history table is not installed yet.');

            return self::SUCCESS;
        }

        /*
         * --days=0 is a deliberate "keep everything", not an absent option.
         * A truthiness check here would read zero as unset and delete
         * against the default window instead — the exact opposite.
         */
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) setting('login_activity_retention_days', self::DEFAULT_DAYS);

        if ($days <= 0) {
            $this->components->info('Retention is set to keep every sign-in; nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff  = now()->subDays($days);
        $ceiling = max(0, (int) $this->option('limit'));
        $deleted = 0;

        do {
            $batch = min(self::BATCH, $ceiling > 0 ? $ceiling - $deleted : self::BATCH);

            if ($batch <= 0) {
                break;
            }

            $removed = LoginActivity::query()
                ->where('login_at', '<', $cutoff)
                ->limit($batch)
                ->delete();

            $deleted += $removed;
        } while ($removed >= $batch);

        $this->components->info(sprintf(
            'Pruned %d sign-in record(s) older than %s.',
            $deleted,
            $cutoff->toDateString()
        ));

        return self::SUCCESS;
    }
}
