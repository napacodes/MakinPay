<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\BillPayment\BillPaymentStatusChecker;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Ask a payment's provider for its status, through BillPaymentStatusChecker.
 *
 * Deliberately not queued: a provider notice dispatches it with
 * dispatchAfterResponse(), so it runs in the same process once the reply has
 * gone out and needs no queue worker. The checker's per-payment lock stops it
 * from asking twice at the same moment as a reconcile run or an admin.
 */
class CheckBillPaymentStatus
{
    use Dispatchable;

    public function __construct(
        public int $paymentId,
        public string $trigger = BillPaymentStatusChecker::TRIGGER_WEBHOOK,
    ) {}

    /**
     * A failure is reported, never rethrown: the reply has already gone out,
     * and the next reconcile run asks again.
     */
    public function handle(BillPaymentStatusChecker $checker): void
    {
        try {
            $checker->check($this->paymentId, $this->trigger);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
