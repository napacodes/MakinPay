<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MassPayment\MassPaymentActionResult;
use App\Services\MassPayment\MassPayoutReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the payout providers about the mass payout lines still waiting on an
 * answer, read-only.
 *
 * Every due line ({@see MassPayoutReconciler::due()}) is looked up by its own
 * request id and settled, refunded or rescheduled on exactly what the
 * provider says. Nothing is ever sent again from here, and a line is never
 * refunded for want of an answer: one that stays unanswered goes to review.
 * Each line is on its own: one that throws is reported and the run goes on.
 */
class ReconcileMassPayouts extends Command
{
    protected $signature = 'mass-payouts:reconcile
                            {--limit=100 : How many lines to check in one run (1-1000)}
                            {--max-seconds=50 : Stop starting new checks after this many seconds}';

    protected $description = 'Check mass payout lines awaiting their provider and settle, refund or reschedule them on its answer';

    public function handle(MassPayoutReconciler $reconciler): int
    {
        $limit  = max(1, min(1000, (int) $this->option('limit')));
        $stopAt = CarbonImmutable::now()->addSeconds(max(1, (int) $this->option('max-seconds')));

        $checked = 0;
        $errors  = 0;
        $stopped = false;

        foreach ($reconciler->due($limit) as $rowId) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($stopAt)) {
                $stopped = true;

                break;
            }

            try {
                if (! $reconciler->check($rowId)->is(MassPaymentActionResult::ALREADY_RESOLVED)) {
                    $checked++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
            }
        }

        $this->info(sprintf(
            '%d payout lines checked, %d errored.%s',
            $checked,
            $errors,
            $stopped ? ' Stopped at the time limit.' : '',
        ));

        return self::SUCCESS;
    }
}
