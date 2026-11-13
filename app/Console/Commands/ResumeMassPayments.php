<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Models\MassPaymentBatch;
use App\Services\MassPayment\MassPaymentProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Throwable;

/**
 * Keep mass payments moving when nothing else does.
 *
 *   1. Stalled. A Queued or Processing batch with no progress for
 *      STALE_AFTER_MINUTES (from `last_progress_at`, or `confirmed_at`), and a
 *      batch held for review or awaiting approval whose remaining lines were
 *      cancelled, is run here directly, up to ROWS_PER_BATCH lines each. An
 *      install with cron and no queue worker still pays out this way. A
 *      payout batch is run by the payout processor, which only ever sends a
 *      line still Reserved.
 *   2. Unfinished. A Queued or Processing batch with no line left to pay, and
 *      a held or awaiting batch whose cancelled lines have all been refunded,
 *      is finalized, which covers a crash between its last line and finalize.
 *
 * The processor takes the same per-batch cache lock the job and an inline
 * stop take, so a batch someone else is working is skipped, and every line is
 * idempotent besides. Each batch is on its own: one that throws is reported
 * and the run goes on.
 */
class ResumeMassPayments extends Command
{
    public const STALE_AFTER_MINUTES = 2;

    public const ROWS_PER_BATCH = 200;

    protected $signature = 'mass-payments:resume
                            {--limit=50 : How many batches each step takes in one run (1-500)}
                            {--max-seconds=50 : Stop starting new batches after this many seconds}';

    protected $description = 'Run stalled mass payment batches directly and finalize the ones whose rows are all done';

    public function handle(MassPaymentProcessor $processor): int
    {
        $limit  = max(1, min(500, (int) $this->option('limit')));
        $stopAt = CarbonImmutable::now()->addSeconds(max(1, (int) $this->option('max-seconds')));

        $errors  = 0;
        $resumed = 0;
        $stopped = false;

        foreach ($this->stalled($limit) as $batchId) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($stopAt)) {
                $stopped = true;

                break;
            }

            try {
                $processor->run($batchId, self::ROWS_PER_BATCH, $stopAt);
                $resumed++;
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
            }
        }

        $finalized = 0;

        foreach ($this->unfinished($limit) as $batchId) {
            try {
                $processor->finalize($batchId);
                $finalized++;
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
            }
        }

        $this->info(sprintf(
            '%d stalled batches run, %d finalize checks, %d errored.%s',
            $resumed,
            $finalized,
            $errors,
            $stopped ? ' Stopped at the time limit.' : '',
        ));

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function stalled(int $limit): array
    {
        $before = CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES);

        return $this->ids(MassPaymentBatch::query()
            ->where(static fn (Builder $running): Builder => $running
                ->whereIn('status', [MassPaymentBatchStatus::Queued->value, MassPaymentBatchStatus::Processing->value])
                ->orWhere(static fn (Builder $cancelled): Builder => $cancelled
                    ->whereIn('status', [MassPaymentBatchStatus::NeedsReview->value, MassPaymentBatchStatus::AwaitingApproval->value])
                    ->whereNotNull('stop_requested_at')))
            ->where(static fn (Builder $quiet): Builder => $quiet
                ->where('last_progress_at', '<', $before)
                ->orWhere(static fn (Builder $never): Builder => $never
                    ->whereNull('last_progress_at')
                    ->where('confirmed_at', '<', $before)))
            ->whereExists($this->linesToPay(...))
            ->orderBy('id'), $limit);
    }

    /**
     * Batches to finalize: a running one with no line left to pay, and a held
     * one whose remaining lines were cancelled and all refunded. The second is
     * what a crash between the last refund and finalize leaves behind, and no
     * admin action can close it: cancel and resume see the stop request, and
     * close after review finds nothing flagged. A held batch with any line
     * still pending is left to the admin; finalize would refuse it anyway.
     *
     * @return list<int>
     */
    private function unfinished(int $limit): array
    {
        return $this->ids(MassPaymentBatch::query()
            ->where(fn (Builder $finished): Builder => $finished
                ->where(fn (Builder $running): Builder => $running
                    ->whereIn('status', [MassPaymentBatchStatus::Queued->value, MassPaymentBatchStatus::Processing->value])
                    ->whereNotExists($this->linesToPay(...)))
                ->orWhere(fn (Builder $cancelled): Builder => $cancelled
                    ->whereIn('status', [MassPaymentBatchStatus::NeedsReview->value, MassPaymentBatchStatus::AwaitingApproval->value])
                    ->whereNotNull('stop_requested_at')
                    ->whereNotExists($this->pendingLines(...))))
            ->orderBy('id'), $limit);
    }

    /** The batch still has a pending line, flagged or not. */
    private function pendingLines(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('mass_payment_rows')
            ->whereColumn('mass_payment_rows.mass_payment_batch_id', 'mass_payment_batches.id')
            ->where('mass_payment_rows.status', MassPaymentRowStatus::Pending->value);
    }

    /** The batch still has a pending line that is not flagged for review. */
    private function linesToPay(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('mass_payment_rows')
            ->whereColumn('mass_payment_rows.mass_payment_batch_id', 'mass_payment_batches.id')
            ->where('mass_payment_rows.status', MassPaymentRowStatus::Pending->value)
            ->where('mass_payment_rows.needs_review', false);
    }

    /**
     * @param  Builder<MassPaymentBatch> $query
     * @return list<int>
     */
    private function ids(Builder $query, int $limit): array
    {
        return $query->limit($limit)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
