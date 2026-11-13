<?php

namespace App\Console\Commands;

use App\Models\Observability\ErrorDeliveryLog;
use App\Models\Observability\ErrorEvent;
use App\Models\Observability\ErrorStatBucket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly retention pruning.
 *
 * Deleted in bounded batches with a hard ceiling per run. A single unbounded
 * DELETE over the largest table in the module would hold a lock long enough to
 * stall the collector on the shared hosting most CodeCanyon buyers run, and
 * the tail of the backlog is cleared by the next night's run instead.
 *
 * Groups are never pruned: they are one row per bug, they are what the Issues
 * list reads, and their counters are the record that the events existed at all.
 */
class PruneObservabilityData extends Command
{
    protected $signature = 'observability:prune {--days=} {--limit=0 : Stop after this many occurrences; 0 runs until the window is clear}';

    protected $description = 'Remove captured error occurrences past the configured retention window';

    /**
     * Rows per DELETE. Small enough that the lock is never held long.
     */
    private const BATCH = 500;

    public function handle(): int
    {
        if (! Schema::hasTable('error_events')) {
            $this->components->warn('The observability tables are not installed yet.');

            return self::SUCCESS;
        }

        /*
         * --days=0 is a deliberate "keep everything", not an absent
         * option. A truthiness check here would read zero as unset and
         * quietly delete against the default window instead.
         */
        $option = $this->option('days');
        $days   = $option === null
            ? (int) setting('observability_retention_days', 30)
            : (int) $option;

        if ($days < 1) {
            $this->components->warn('Retention is disabled, so nothing was removed.');

            return self::SUCCESS;
        }

        $cutoff  = now()->subDays($days);
        $ceiling = max(0, (int) $this->option('limit'));
        $removed = 0;

        // Small batches bound the lock time; the loop runs until the window
        // is clear, so retention catches up after an incident instead of
        // falling further behind every day.
        while ($ceiling === 0 || $removed < $ceiling) {
            $deleted = ErrorEvent::query()
                ->where('created_at', '<', $cutoff)
                ->limit($ceiling === 0 ? self::BATCH : min(self::BATCH, $ceiling - $removed))
                ->delete();

            if ($deleted === 0) {
                break;
            }

            $removed += $deleted;

            usleep(20000);
        }

        $deliveries = 0;

        do {
            $deleted = ErrorDeliveryLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::BATCH)
                ->delete();

            $deliveries += $deleted;
        } while ($deleted > 0);

        $buckets = ErrorStatBucket::query()->where('bucket_at', '<', $cutoff)->delete();

        $this->components->info(sprintf(
            'Removed %d occurrences, %d delivery log rows and %d rollup buckets older than %d days.',
            $removed,
            $deliveries,
            $buckets,
            $days
        ));

        return self::SUCCESS;
    }
}
