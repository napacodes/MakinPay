<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Contracts\BillPayment\ChecksBillPaymentStatus;
use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\BillPayment\BillPaymentOutcome;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\Admin;
use App\Models\BillPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Asks a payment's provider what became of it, and records the answer through
 * BillPaymentService::applyResult(), the one place an answer is applied.
 *
 * Never calls pay(): a payment is sent at most once, from dispatch(). A check
 * only reads the provider's status, so it is safe to repeat, and a lock per
 * payment keeps two checks (the reconcile run and a provider notice, say)
 * from asking at the same moment.
 *
 *   open payment      only once it reached the provider (awaiting_provider or
 *                     needs_review). The answer is applied; while the payment
 *                     still waits on the provider the next check is scheduled
 *                     on the backoff, and one past its deadline or out of
 *                     attempts goes to a person. Never refunded for that.
 *   resolved payment  only for a provider notice or an admin, within
 *                     `recheck_terminal_days`. An answer that contradicts it
 *                     is flagged; nothing else is applied and no money moves.
 *
 * A throw from the driver is an unclear answer, never a refusal.
 */
class BillPaymentStatusChecker
{
    public const TRIGGER_RECONCILE = 'reconcile';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_ADMIN = 'admin';

    /** How long one check may hold the payment's check lock. */
    public const LOCK_SECONDS = 60;

    public function __construct(
        private readonly BillPaymentService $service,
        private readonly BillPaymentProviderManager $providers,
    ) {}

    /** The cache lock one check of this payment holds. */
    public static function lockKey(int $paymentId): string
    {
        return 'bill-payment-check:'.$paymentId;
    }

    /**
     * @param string $trigger one of the TRIGGER_* constants
     * @param ?Admin $admin   the admin who asked; required for TRIGGER_ADMIN
     *
     * @throws InvalidArgumentException an unknown trigger, or an admin check without the admin
     */
    public function check(int $paymentId, string $trigger, ?Admin $admin = null): BillPaymentCheckOutcome
    {
        if (! in_array($trigger, [self::TRIGGER_RECONCILE, self::TRIGGER_WEBHOOK, self::TRIGGER_ADMIN], true)) {
            throw new InvalidArgumentException("Unknown bill payment check trigger [{$trigger}].");
        }

        if ($trigger === self::TRIGGER_ADMIN && $admin === null) {
            throw new InvalidArgumentException('An admin check needs the admin who asked.');
        }

        $lock = Cache::lock(self::lockKey($paymentId), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return BillPaymentCheckOutcome::skipped(__('A check for this payment is already running.'));
        }

        try {
            return $this->checkUnderLock($paymentId, $trigger, $admin);
        } finally {
            $lock->release();
        }
    }

    private function checkUnderLock(int $paymentId, string $trigger, ?Admin $admin): BillPaymentCheckOutcome
    {
        $payment = BillPayment::query()->with(['provider', 'biller'])->find($paymentId);

        if ($payment === null) {
            return BillPaymentCheckOutcome::skipped(__('The payment was not found.'));
        }

        if ($payment->fulfilment !== BillPaymentFulfilment::Provider) {
            return BillPaymentCheckOutcome::skipped(__('This payment is not sent to a provider, so there is nothing to check.'));
        }

        $driver = $this->statusDriver($payment);

        if ($driver === null) {
            return BillPaymentCheckOutcome::skipped(__('This provider cannot be asked about a payment.'));
        }

        $actor = $admin !== null && $trigger === self::TRIGGER_ADMIN
            ? BillPaymentActor::admin($admin)
            : BillPaymentActor::provider();

        if ($this->isResolved($payment)) {
            return $this->recheckResolved($payment, $driver, $trigger, $actor);
        }

        if (! in_array($payment->dispatchState(), [BillPaymentDispatchState::AwaitingProvider, BillPaymentDispatchState::NeedsReview], true)) {
            return BillPaymentCheckOutcome::skipped(__('This payment has not reached the provider yet.'));
        }

        $result = $this->ask($driver, $payment);
        $action = $this->service->applyResult($paymentId, $result, $actor);

        $this->recordAttempt($paymentId, $result);

        if ($trigger === self::TRIGGER_ADMIN
            && in_array($result->outcome, [BillPaymentOutcome::Pending, BillPaymentOutcome::Unknown], true)) {
            $current = BillPayment::query()->find($paymentId);

            if ($current !== null && ! $this->isResolved($current)) {
                $this->service->recordProviderCheck($paymentId, $result->outcome, $actor);
            }
        }

        return BillPaymentCheckOutcome::asked($result->outcome, $this->message($result->outcome), $action);
    }

    /**
     * Ask about a payment already settled or refunded. Only an answer that
     * contradicts it reaches applyResult(), which flags it and moves no money.
     */
    private function recheckResolved(
        BillPayment $payment,
        ChecksBillPaymentStatus $driver,
        string $trigger,
        BillPaymentActor $actor,
    ): BillPaymentCheckOutcome {
        if ($trigger === self::TRIGGER_RECONCILE) {
            return BillPaymentCheckOutcome::skipped(__('This payment is already resolved.'));
        }

        if (BillPaymentPollingSchedule::resolvedTooLongAgo($payment)) {
            return BillPaymentCheckOutcome::skipped(__('This payment was resolved too long ago to check again.'));
        }

        $result = $this->ask($driver, $payment);

        $contradicts = ($payment->status === BillPaymentStatus::Completed && $result->outcome === BillPaymentOutcome::Failed)
            || ($payment->status === BillPaymentStatus::Failed && $result->outcome === BillPaymentOutcome::Completed);

        $action = $contradicts ? $this->service->applyResult((int) $payment->id, $result, $actor) : null;

        return BillPaymentCheckOutcome::asked($result->outcome, $this->message($result->outcome), $action);
    }

    /**
     * Count the check on a payment still waiting on its provider, schedule
     * the next one on the backoff, keep a final-status time the provider gave
     * if none is stored, and hand a payment past its deadline or out of
     * attempts to a person. Bookkeeping only: no money moves and, unless the
     * payment goes to review, no audit row is written.
     */
    private function recordAttempt(int $paymentId, BillPaymentResult $result): void
    {
        DB::transaction(function () use ($paymentId, $result): void {
            $payment = BillPayment::query()->lockForUpdate()->find($paymentId);

            if ($payment === null
                || $this->isResolved($payment)
                || $payment->dispatchState() !== BillPaymentDispatchState::AwaitingProvider) {
                return;
            }

            $attempts = min(65535, (int) $payment->provider_check_attempts + 1);
            $changes  = [
                'provider_check_attempts' => $attempts,
                'provider_checked_at'     => CarbonImmutable::now(),
                'provider_next_check_at'  => BillPaymentPollingSchedule::nextCheckAt($attempts),
            ];

            if ($payment->provider_final_status_at === null) {
                $finalStatusAt = BillPaymentPollingSchedule::finalStatusAt($result->payload['final_status_at'] ?? null, $payment->submitted_at);

                if ($finalStatusAt !== null) {
                    $changes['provider_final_status_at'] = $finalStatusAt;
                }
            }

            $payment->forceFill($changes)->save();

            if (BillPaymentPollingSchedule::isOverdue($payment)) {
                $this->service->parkForReview(
                    $paymentId,
                    BillPaymentService::REVIEW_PROVIDER_UNRESOLVED,
                    BillPaymentActor::system(),
                    __('The provider gave no final answer in time. Check the payment with the provider.'),
                    ['provider_check_attempts' => $attempts],
                );
            }
        }, 3);
    }

    /** The provider's answer; a throw is an unclear answer, never a refusal. */
    private function ask(ChecksBillPaymentStatus $driver, BillPayment $payment): BillPaymentResult
    {
        try {
            return $driver->status(BillPaymentOrder::from($payment));
        } catch (Throwable $exception) {
            report($exception);

            return BillPaymentResult::unknown(__('The provider did not give a clear answer.'));
        }
    }

    /** The payment's driver when it can be asked for a status, otherwise null. */
    private function statusDriver(BillPayment $payment): ?ChecksBillPaymentStatus
    {
        if ($payment->provider === null) {
            return null;
        }

        try {
            $driver = $this->providers->driverFor($payment->provider);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $driver instanceof ChecksBillPaymentStatus ? $driver : null;
    }

    private function isResolved(BillPayment $payment): bool
    {
        return ($payment->status?->isTerminal() ?? false) || $payment->dispatchState()->isTerminal();
    }

    private function message(BillPaymentOutcome $outcome): string
    {
        return match ($outcome) {
            BillPaymentOutcome::Completed => __('The provider reports this payment as paid.'),
            BillPaymentOutcome::Failed    => __('The provider reports this payment as not paid.'),
            BillPaymentOutcome::Pending   => __('The provider is still processing this payment.'),
            BillPaymentOutcome::Unknown   => __('The provider gave no clear answer about this payment.'),
        };
    }
}
