<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Services\MassPayment\MassPaymentActor;
use App\Services\MassPayment\MassPaymentProcessor;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pay out one confirmed mass payment through MassPaymentProcessor.
 *
 * Each run pays up to ROW_BUDGET lines or works for RUN_SECONDS, whichever
 * comes first, then releases itself back onto the queue while lines remain.
 * It never dispatches itself again: the unique lock would drop that copy.
 *
 * Unique per batch, and the processor takes its own per-batch lock as well,
 * so the job and the every-minute resume command never pay the same batch at
 * the same moment. Every line is idempotent besides, so a duplicate run would
 * pay nothing twice.
 *
 * failed() never touches money: a line still pending holds its reserved
 * debit and stays safely resumable by the resume command or an admin.
 */
class ProcessMassPaymentBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const ROW_BUDGET = 500;

    public const RUN_SECONDS = 240;

    public const CONTINUE_AFTER_SECONDS = 5;

    public const RETRY_FOR_HOURS = 6;

    public int $timeout = 300;

    public int $maxExceptions = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_FOR_HOURS);
    }

    public function handle(MassPaymentProcessor $processor): void
    {
        $processor->run($this->batchId, self::ROW_BUDGET, now()->addSeconds(self::RUN_SECONDS));

        if ($processor->hasRunnableRows($this->batchId)) {
            $this->release(self::CONTINUE_AFTER_SECONDS);
        }
    }

    /**
     * Record that the queue gave up on the batch. Only the exception's class
     * is kept: its message may carry provider or database detail.
     */
    public function failed(?Throwable $exception): void
    {
        $batch = MassPaymentBatch::query()->find($this->batchId);

        if ($batch === null) {
            return;
        }

        $status = (string) $batch->getAttribute('status');

        MassPaymentEvent::query()->create([
            'mass_payment_batch_id' => $batch->id,
            'from_status'           => $status,
            'to_status'             => $status,
            'actor_type'            => MassPaymentActor::TYPE_SYSTEM,
            'note'                  => 'processing_job_failed',
            'payload'               => $exception === null ? null : ['exception' => $exception::class],
        ]);
    }
}
