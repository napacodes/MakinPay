<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Enums\Payout\PayoutOutcome;
use App\Exceptions\NotifyErrorException;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Models\MassPaymentRow;
use App\Services\Payment\Payout\PayoutContradictionException;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Payment\Payout\PayoutNotFoundException;
use App\Services\Payment\Payout\PayoutOrderGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Settles the mass payout lines whose provider has not yet given a final
 * answer, by asking it - read-only, never sending anything again.
 *
 * A line is due when it is AwaitingProvider, or still Submitting past the
 * worst case of the call that claimed it (a crash between claim and record),
 * and its `next_check_at` has come. Its driver's lookup() is then asked by
 * the line's own request id, and the answer goes through
 * {@see MassPayoutProcessor::recordResult()} exactly as the send's would:
 * completed settles, failed refunds, anything else waits longer.
 *
 * What never happens here: a refund on silence. A lookup that finds nothing,
 * that errors, or that keeps answering "pending" leaves the line waiting, and
 * once that has gone on too long the line goes to an admin for review
 * (NOT_FOUND_REVIEW_AFTER_HOURS for nothing found, UNCONFIRMED_REVIEW_AFTER_HOURS
 * for anything else). An answer that cannot describe one payout goes to review
 * at once.
 *
 * A provider's webhook about one of these lines is recorded by
 * {@see self::notice()}, deduplicated by its event id, and only brings the
 * line's next check forward. The webhook body never settles or refunds
 * anything: the lookup it prompts does, on the provider's own answer.
 */
class MassPayoutReconciler
{
    public const CHECK_LOCK_PREFIX = 'mass-payout:check:';

    public const CHECK_LOCK_SECONDS = 300;

    /** A line its provider still knows nothing about this long after the send goes to review. */
    public const NOT_FOUND_REVIEW_AFTER_HOURS = 24;

    /** A line with no final answer this long after the send goes to review. */
    public const UNCONFIRMED_REVIEW_AFTER_HOURS = 72;

    public const REVIEW_NOT_FOUND = 'provider_not_found';

    public const REVIEW_UNCONFIRMED = 'unconfirmed';

    public const REVIEW_CONTRADICTION = 'provider_contradiction';

    private const EVENT_ID_MAX_LENGTH = 191;

    public function __construct(
        private readonly MassPayoutProcessor $payouts,
        private readonly MassPaymentProcessor $rows,
        private readonly PayoutGatewayFactory $gateways,
    ) {}

    /**
     * The lines due a check, the most overdue first.
     *
     * @return list<int>
     */
    public function due(int $limit): array
    {
        return MassPaymentRow::query()
            ->whereIn('dispatch_state', [MassPaymentDispatchState::AwaitingProvider->value, MassPaymentDispatchState::Submitting->value])
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->where('needs_review', false)
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Ask the provider about one due line and record its answer. A line
     * someone else is already checking, or one no longer due, is skipped as
     * `already_resolved`. Finalizes the batch once its last line resolves.
     */
    public function check(int $rowId): MassPaymentActionResult
    {
        $lock = Cache::lock(self::CHECK_LOCK_PREFIX.$rowId, self::CHECK_LOCK_SECONDS);

        if (! $lock->get()) {
            return MassPaymentActionResult::alreadyResolved();
        }

        try {
            $result = $this->ask($rowId);
        } finally {
            $lock->release();
        }

        $row = MassPaymentRow::query()->find($rowId);

        if ($row !== null && ! $this->hasPendingRows((int) $row->mass_payment_batch_id)) {
            $this->rows->finalize((int) $row->mass_payment_batch_id);
        }

        return $result;
    }

    /**
     * Ask the provider about one line now, on an admin's request, whether or
     * not it is due, and return what the provider said. A line with its
     * provider or under review is asked; one still Submitting is refused
     * until its call could have finished.
     *
     * The answer is recorded as any check's: completed settles, failed
     * refunds, a pending answer puts a line under review back to waiting on
     * the provider. Nothing found, or no answer, leaves a line under review
     * where it is, with the check recorded - which is what lets an admin then
     * confirm it not paid ({@see MassPayoutService::markRowNotPaid()}).
     *
     * @throws NotifyErrorException the line is not with a provider, or is still being sent
     */
    public function checkNow(int $rowId): PayoutOutcome
    {
        $lock = Cache::lock(self::CHECK_LOCK_PREFIX.$rowId, self::CHECK_LOCK_SECONDS);

        if (! $lock->get()) {
            throw new NotifyErrorException(__('This payout is being checked right now. Try again in a moment.'), 409);
        }

        try {
            return $this->askNow($rowId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws NotifyErrorException
     */
    private function askNow(int $rowId): PayoutOutcome
    {
        $row   = MassPaymentRow::query()->findOrFail($rowId);
        $state = $row->dispatchState();

        if ($row->status() !== MassPaymentRowStatus::Pending
            || $row->needs_review
            || ! in_array($state, [MassPaymentDispatchState::AwaitingProvider, MassPaymentDispatchState::Submitting, MassPaymentDispatchState::NeedsReview], true)) {
            throw new NotifyErrorException(__('Only a payout already with its provider can be checked.'), 409);
        }

        if ($state === MassPaymentDispatchState::Submitting && $row->next_check_at !== null && $row->next_check_at->isFuture()) {
            throw new NotifyErrorException(__('This payout is still being sent. Check again in a minute.'), 409);
        }

        $batch   = MassPaymentBatch::query()->findOrFail($row->mass_payment_batch_id);
        $gateway = $this->gateways->massPayoutGateway((string) $row->payout_driver);

        try {
            $result = $gateway->lookup($this->payouts->order($batch, $row));
        } catch (PayoutNotFoundException) {
            $this->noAnswer($row, $state, PayoutOutcome::NotFound, $gateway);

            return PayoutOutcome::NotFound;
        } catch (PayoutContradictionException) {
            $this->payouts->toReview($rowId, $state, self::REVIEW_CONTRADICTION);

            return PayoutOutcome::Unknown;
        } catch (Throwable $exception) {
            report($exception);
            $this->noAnswer($row, $state, PayoutOutcome::Unknown, $gateway);

            return PayoutOutcome::Unknown;
        }

        $this->payouts->recordResult($rowId, $state, $result, $gateway, true);

        return PayoutOutcome::fromStatus($result->status);
    }

    /** A check that settled nothing: a line under review stays there with the check noted; any other waits. */
    private function noAnswer(MassPaymentRow $row, MassPaymentDispatchState $state, PayoutOutcome $outcome, PayoutOrderGateway $gateway): void
    {
        if ($state === MassPaymentDispatchState::NeedsReview) {
            $this->payouts->noteCheck((int) $row->id, $state, $outcome);

            return;
        }

        $this->payouts->awaitProvider((int) $row->id, $state, $outcome, null, $gateway, true);
    }

    /**
     * Record a provider notice about a payout line and bring its next check
     * forward. Moves no money, whatever the notice says. A notice already
     * recorded for the line (same event id) changes nothing. Returns whether
     * the reference named a payout line of this driver at all, so the caller
     * knows not to look elsewhere for it.
     */
    public function notice(string $driver, string $reference, string $eventId, string $eventName): bool
    {
        $reference = trim($reference);

        if ($reference === '') {
            return false;
        }

        $row = MassPaymentRow::query()
            ->where('payout_driver', $driver)
            ->where(fn ($query) => $query
                ->where('provider_request_id', $reference)
                ->orWhere('provider_reference', $reference))
            ->first();

        if ($row === null) {
            return false;
        }

        try {
            MassPaymentEvent::query()->create([
                'mass_payment_batch_id' => $row->mass_payment_batch_id,
                'row_id'                => $row->id,
                'from_status'           => (string) $row->getAttribute('dispatch_state'),
                'to_status'             => (string) $row->getAttribute('dispatch_state'),
                'actor_type'            => MassPaymentActor::TYPE_SYSTEM,
                'note'                  => 'provider_notice',
                'payload'               => ['driver' => $driver, 'event' => mb_substr($eventName, 0, 100)],
                'external_event_id'     => mb_substr($eventId, 0, self::EVENT_ID_MAX_LENGTH),
            ]);
        } catch (QueryException $duplicate) {
            if (! $this->isDuplicate($duplicate)) {
                throw $duplicate;
            }

            return true;
        }

        MassPaymentRow::query()
            ->whereKey($row->id)
            ->where('dispatch_state', MassPaymentDispatchState::AwaitingProvider->value)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->update(['next_check_at' => now()]);

        return true;
    }

    private function ask(int $rowId): MassPaymentActionResult
    {
        $row   = MassPaymentRow::query()->find($rowId);
        $state = $row?->dispatchState();

        if ($row === null
            || $row->status() !== MassPaymentRowStatus::Pending
            || $row->needs_review
            || ! in_array($state, [MassPaymentDispatchState::AwaitingProvider, MassPaymentDispatchState::Submitting], true)
            || $row->next_check_at === null
            || $row->next_check_at->isFuture()) {
            return MassPaymentActionResult::alreadyResolved(null, $row);
        }

        $batch = MassPaymentBatch::query()->findOrFail($row->mass_payment_batch_id);

        try {
            $gateway = $this->gateways->massPayoutGateway((string) $row->payout_driver);
        } catch (Throwable $exception) {
            report($exception);

            return $this->waitOrReview($row, $state, PayoutOutcome::Unknown, null);
        }

        if (! $gateway->supportsLookup()) {
            return $this->payouts->awaitProvider($rowId, $state, PayoutOutcome::Unknown, null, $gateway, true);
        }

        try {
            $result = $gateway->lookup($this->payouts->order($batch, $row));
        } catch (PayoutNotFoundException) {
            return $this->waitOrReview($row, $state, PayoutOutcome::NotFound, $gateway);
        } catch (PayoutContradictionException) {
            return $this->payouts->toReview($rowId, $state, self::REVIEW_CONTRADICTION);
        } catch (Throwable $exception) {
            report($exception);

            return $this->waitOrReview($row, $state, PayoutOutcome::Unknown, $gateway);
        }

        $recorded = $this->payouts->recordResult($rowId, $state, $result, $gateway, true);
        $fresh    = MassPaymentRow::query()->find($rowId);

        if ($fresh?->dispatchState() === MassPaymentDispatchState::AwaitingProvider && $this->isOverdue($fresh, self::UNCONFIRMED_REVIEW_AFTER_HOURS)) {
            return $this->payouts->toReview($rowId, MassPaymentDispatchState::AwaitingProvider, self::REVIEW_UNCONFIRMED, $result);
        }

        return $recorded;
    }

    /**
     * No answer this time: wait longer, or, once the line has waited past its
     * deadline, hand it to an admin. Never a refund.
     */
    private function waitOrReview(MassPaymentRow $row, MassPaymentDispatchState $state, PayoutOutcome $outcome, ?PayoutOrderGateway $gateway): MassPaymentActionResult
    {
        [$hours, $reason] = $outcome === PayoutOutcome::NotFound
            ? [self::NOT_FOUND_REVIEW_AFTER_HOURS, self::REVIEW_NOT_FOUND]
            : [self::UNCONFIRMED_REVIEW_AFTER_HOURS, self::REVIEW_UNCONFIRMED];

        if ($this->isOverdue($row, $hours)) {
            return $this->payouts->toReview((int) $row->id, $state, $reason);
        }

        return $this->payouts->awaitProvider((int) $row->id, $state, $outcome, null, $gateway, true);
    }

    private function isOverdue(MassPaymentRow $row, int $hours): bool
    {
        $since = $row->submitted_at ?? $row->updated_at;

        return $since !== null && CarbonImmutable::instance($since)->addHours($hours)->isPast();
    }

    private function hasPendingRows(int $batchId): bool
    {
        return MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batchId)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->exists();
    }

    /** The insert broke the unique (row, external event) index: the notice is a replay. */
    private function isDuplicate(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');

        return $state === '23000' || $state === '23505';
    }
}
