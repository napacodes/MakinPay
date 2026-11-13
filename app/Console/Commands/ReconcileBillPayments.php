<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Exceptions\NotifyErrorException;
use App\Models\BillPayment;
use App\Services\BillPayment\BillPaymentActor;
use App\Services\BillPayment\BillPaymentResult;
use App\Services\BillPayment\BillPaymentService;
use App\Services\BillPayment\BillPaymentStatusChecker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Finish the provider bill payments that did not finish themselves.
 *
 *   1. Never sent. A payment still `reserved` with no `submitted_at` after
 *      `stale_reserved_minutes` was never handed to its driver, so it is
 *      refunded. It is never sent now: the customer may already have paid
 *      again under a new request. refund() checks `submitted_at` again under
 *      the payment's lock.
 *   2. Stuck handing over. A payment `submitting` for longer than
 *      `stale_submitting_minutes` lost the driver's answer. It is recorded as
 *      an unclear answer: awaiting_provider when the driver can be asked,
 *      otherwise needs_review. Never refunded, never sent again.
 *   3. Due checks. Payments awaiting the provider whose next check is due are
 *      asked about, oldest first, until the time budget runs out.
 *
 * Runs inline, so it needs no queue worker. Every row is on its own: one that
 * throws is reported and the run goes on. pay() is never called from here.
 */
class ReconcileBillPayments extends Command
{
    protected $signature = 'bill-payment:reconcile
                            {--limit=50 : How many payments each step takes in one run (1-1000)}
                            {--max-seconds= : Stop asking providers after this many seconds (default: bill_payments.reconcile.max_seconds)}
                            {--dry-run : Count what each step would do without changing anything}';

    protected $description = 'Refund never-sent bill payments, recover stuck dispatches and poll providers for pending payments';

    public function handle(BillPaymentService $service, BillPaymentStatusChecker $checker): int
    {
        $limit      = max(1, min(1000, (int) $this->option('limit')));
        $maxSeconds = max(1, (int) ($this->option('max-seconds') ?? config('bill_payments.reconcile.max_seconds', 240)));
        $stopAt     = CarbonImmutable::now()->addSeconds($maxSeconds);
        $dryRun     = (bool) $this->option('dry-run');

        $neverSent = $this->neverSent($limit);
        $stuck     = $this->stuckHandingOver($limit);

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: %d never sent would be refunded, %d stuck handing over would be recorded as unclear, %d due for a provider check.',
                count($neverSent),
                count($stuck),
                count($this->dueChecks($limit)),
            ));

            return self::SUCCESS;
        }

        $errors = 0;

        $refunded = $this->each($neverSent, $errors, static fn (int $id) => $service->refund(
            $id,
            __('The payment was not sent to the biller.'),
            BillPaymentActor::system(),
            [BillPaymentDispatchState::Reserved],
        ));

        $recovered = $this->each($stuck, $errors, static fn (int $id) => $service->applyResult(
            $id,
            BillPaymentResult::unknown(__('Dispatch did not record an answer.')),
            BillPaymentActor::system(),
        ));

        $checked = 0;
        $stopped = false;

        foreach ($this->dueChecks($limit) as $id) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($stopAt)) {
                $stopped = true;

                break;
            }

            $checked += $this->each([$id], $errors, static fn (int $paymentId) => $checker->check($paymentId, BillPaymentStatusChecker::TRIGGER_RECONCILE));
        }

        $this->info(sprintf(
            '%d never sent refunded, %d stuck handing over recorded, %d checked with the provider, %d errored.%s',
            $refunded,
            $recovered,
            $checked,
            $errors,
            $stopped ? ' Stopped at the time limit.' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Run $action for each payment id on its own. A payment another process
     * moved meanwhile is refused by the service and skipped; anything else
     * that throws is reported. Returns how many ran without throwing.
     *
     * @param list<int>            $ids
     * @param callable(int): mixed $action
     */
    private function each(array $ids, int &$errors, callable $action): int
    {
        $done = 0;

        foreach ($ids as $id) {
            try {
                $action($id);
                $done++;
            } catch (NotifyErrorException) {
                continue;
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
            }
        }

        return $done;
    }

    /** @return list<int> */
    private function neverSent(int $limit): array
    {
        $minutes = max(1, (int) config('bill_payments.reconcile.stale_reserved_minutes', 10));

        return $this->ids($this->providerPayments(BillPaymentDispatchState::Reserved)
            ->whereNull('submitted_at')
            ->where('created_at', '<', CarbonImmutable::now()->subMinutes($minutes))
            ->orderBy('id'), $limit);
    }

    /** @return list<int> */
    private function stuckHandingOver(int $limit): array
    {
        $minutes = max(1, (int) config('bill_payments.reconcile.stale_submitting_minutes', 10));

        return $this->ids($this->providerPayments(BillPaymentDispatchState::Submitting)
            ->where('submitted_at', '<', CarbonImmutable::now()->subMinutes($minutes))
            ->orderBy('id'), $limit);
    }

    /** @return list<int> */
    private function dueChecks(int $limit): array
    {
        return $this->ids($this->providerPayments(BillPaymentDispatchState::AwaitingProvider)
            ->where(static fn (Builder $due): Builder => $due
                ->whereNull('provider_next_check_at')
                ->orWhere('provider_next_check_at', '<=', CarbonImmutable::now()))
            ->orderBy('provider_next_check_at')
            ->orderBy('id'), $limit);
    }

    /** @return Builder<BillPayment> */
    private function providerPayments(BillPaymentDispatchState $state): Builder
    {
        return BillPayment::query()
            ->where('fulfilment', BillPaymentFulfilment::Provider->value)
            ->where('dispatch_state', $state->value);
    }

    /**
     * @param  Builder<BillPayment> $query
     * @return list<int>
     */
    private function ids(Builder $query, int $limit): array
    {
        return $query->limit($limit)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
