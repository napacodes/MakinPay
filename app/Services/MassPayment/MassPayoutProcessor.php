<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Enums\Payout\PayoutOutcome;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\UserStatus;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Models\MassPaymentRow;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Payment\Payout\PayoutNotSentException;
use App\Services\Payment\Payout\PayoutOrder;
use App\Services\Payment\Payout\PayoutOrderGateway;
use App\Services\Payment\Payout\PayoutProviderUnavailableException;
use App\Services\Payment\Payout\PayoutResult;
use App\Support\DecimalMoney;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a confirmed mass payout to its provider, one line at a time, each at
 * most once.
 *
 * Confirm already took the whole total from the source wallet and wrote one
 * PENDING debit per line, the line Reserved. A line here moves through
 * {@see MassPaymentDispatchState}:
 *
 *   claim     one transaction: Reserved -> Submitting, `submitted_at` set and
 *             the first status check scheduled. Committed BEFORE the provider
 *             is called, so a crash mid-call leaves a line that is known to
 *             have possibly been sent and is only ever looked up, never sent
 *             again.
 *   send      the driver's send(), with no transaction open and no lock held,
 *             keyed by the line's provider request id.
 *   record    one transaction on what the call proved:
 *               Completed           settle: the debit COMPLETED, the line Paid;
 *               Pending / Submitted AwaitingProvider (AwaitingOperator for
 *                                   the Manual driver), checked later;
 *               Failed / NotSent    refund: the whole payable back, exactly
 *                                   one REFUND, the line Refunded;
 *               anything else       AwaitingProvider. Never refunded: the
 *                                   provider may still pay.
 *
 * A provider that refuses our credentials before anything is sent puts the
 * line back to Reserved and holds the batch, since every following line would
 * be refused the same way; an admin resumes it once the credentials work.
 *
 * Settling moves no wallet balance - the money left the wallet at confirm -
 * and refunding goes through {@see MassPaymentProcessor::failRow()}, so a
 * payout line is refunded by the very code, locks and ledger checks that
 * refund a wallet line.
 *
 * Lock order, the module's own (batch, row, debit, exactly one wallet): claim
 * and record lock the row, then its debit; a refund adds the source wallet.
 * Nothing here locks two rows or holds a transaction across a provider call.
 *
 * Every runner of a batch takes {@see MassPaymentProcessor::LOCK_PREFIX}'s
 * lock, the same one the wallet runners take, so the job, the resume command
 * and an inline stop never walk one batch at the same moment.
 */
class MassPayoutProcessor
{
    public const HOLD_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** How long after a call's worst case a line still Submitting is looked up. */
    public const SUBMIT_GRACE_SECONDS = 60;

    /** The first status check of a line the provider accepted but did not finish. */
    public const FIRST_CHECK_SECONDS = 120;

    /** The longest wait between two status checks of one line. */
    public const MAX_CHECK_INTERVAL_SECONDS = 21600;

    /** `metadata.payout_review` on a line the provider contradicted. */
    public const REVIEW_PROVIDER_MISMATCH = 'provider_mismatch';

    public const REVIEW_REFERENCE_TAKEN = 'provider_reference_taken';

    private const FAILURE_REASON_MAX_LENGTH = 500;

    private const PROVIDER_STATUS_MAX_LENGTH = 40;

    private const PROVIDER_REFERENCE_MAX_LENGTH = 128;

    public function __construct(
        private readonly MassPaymentProcessor $rows,
        private readonly PayoutGatewayFactory $gateways,
        private readonly MassPayoutAccount $accounts,
        private readonly MassPaymentNotifier $notifier,
    ) {}

    /**
     * Work through a payout batch's reserved lines, up to `$rowBudget` of
     * them or until `$deadline`, then finalize it once no line is left
     * pending.
     *
     * A stop request refunds every line still Reserved and sends nothing
     * more; lines already in flight finish with their provider. A sender who
     * can no longer pay, or a provider refusing our credentials, holds the
     * batch and ends the walk.
     */
    public function run(int $batchId, int $rowBudget, CarbonInterface $deadline): void
    {
        $lock = Cache::lock(MassPaymentProcessor::LOCK_PREFIX.$batchId, MassPaymentProcessor::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->walk($batchId, $rowBudget, $deadline);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a runner still has lines it may send or refund on this batch:
     * the batch is running (or has a stop request whose refunds must go
     * through) and has a line still Reserved.
     */
    public function hasRunnableRows(int $batchId): bool
    {
        $batch = MassPaymentBatch::query()->find($batchId);

        return $batch !== null && $this->isRunnable($batch) && $this->reservedRows($batchId)->exists();
    }

    /**
     * Send one reserved line, at most once. The result says what became of
     * it: `done` once its outcome is recorded (whatever that outcome),
     * `held` when the provider refused our credentials and the line is
     * Reserved again, `already_resolved` when it was not Reserved to begin
     * with, `anomaly` when its ledger disagreed and it was flagged.
     */
    public function dispatchRow(int $rowId): MassPaymentActionResult
    {
        $claim = $this->claim($rowId);

        if (! $claim->is(MassPaymentActionResult::DONE)) {
            return $claim;
        }

        $row   = MassPaymentRow::query()->findOrFail($rowId);
        $batch = MassPaymentBatch::query()->findOrFail($row->mass_payment_batch_id);

        try {
            $gateway = $this->gateways->massPayoutGateway((string) $row->payout_driver);
            $order   = $this->order($batch, $row);
        } catch (Throwable $exception) {
            return $this->notSent($rowId, $exception);
        }

        try {
            $result = $gateway->send($order);
        } catch (PayoutProviderUnavailableException $exception) {
            return $this->unclaim($rowId, $exception);
        } catch (PayoutNotSentException $exception) {
            return $this->notSent($rowId, $exception);
        } catch (Throwable $exception) {
            report($exception);

            return $this->awaitProvider($rowId, MassPaymentDispatchState::Submitting, PayoutOutcome::Unknown, null, $gateway);
        }

        return $this->recordResult($rowId, MassPaymentDispatchState::Submitting, $result, $gateway);
    }

    /**
     * Record what a provider said about a line in flight: settle it, refund
     * it, or keep it waiting. `$expected` is the state the caller saw the
     * line in; a line found in any other state was already moved on by
     * someone else and is left alone.
     *
     * The answer must describe this line: a reference already bound to
     * another line, or an amount or currency other than the line's, sends the
     * line to review instead. `$isCheck` marks the answer of a read-only
     * status check rather than of the send, which spaces out the next one.
     */
    public function recordResult(int $rowId, MassPaymentDispatchState $expected, PayoutResult $result, ?PayoutOrderGateway $gateway = null, bool $isCheck = false): MassPaymentActionResult
    {
        $row = MassPaymentRow::query()->findOrFail($rowId);

        $mismatch = $this->mismatch($row, $result);

        if ($mismatch !== null) {
            return $this->toReview($rowId, $expected, $mismatch, $result);
        }

        $outcome = PayoutOutcome::fromStatus($result->status);

        return match ($outcome) {
            PayoutOutcome::Completed => $this->settle($rowId, $expected, $result),
            PayoutOutcome::Failed    => $this->refundDefinitive($rowId, $expected, MassPaymentIssue::ProviderRejected, $result),
            default                  => $this->awaitProvider($rowId, $expected, $outcome, $result, $gateway, $isCheck),
        };
    }

    /**
     * Settle a line the provider confirmed paid: its debit COMPLETED with the
     * provider reference, the line Paid and Settled. No balance moves.
     */
    public function settle(int $rowId, MassPaymentDispatchState $expected, ?PayoutResult $result = null, ?string $reference = null, ?int $adminId = null): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $expected, $result, $reference, $adminId): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if (! $this->isStill($row, $expected)) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $debit = $row->debit_transaction_id === null
                ? null
                : Transaction::query()->lockForUpdate()->find($row->debit_transaction_id);

            if (! $this->isReservedDebit($row, $debit)) {
                return $this->flag($row, $debit);
            }

            $providerReference = $this->reference($reference ?? $result?->gatewayReference) ?? $row->provider_reference;

            $debit->forceFill([
                'status'   => TrxStatus::COMPLETED,
                'trx_data' => array_merge(is_array($debit->trx_data) ? $debit->trx_data : [], array_filter([
                    'provider_reference' => $providerReference,
                ], static fn (mixed $value): bool => $value !== null)),
            ])->save();

            $now = now();

            $row->forceFill([
                'status'               => MassPaymentRowStatus::Paid->value,
                'dispatch_state'       => MassPaymentDispatchState::Settled->value,
                'provider_reference'   => $providerReference,
                'provider_status'      => $this->providerStatus($result),
                'last_check_outcome'   => PayoutOutcome::Completed->value,
                'next_check_at'        => null,
                'paid_at'              => $now,
                'settled_at'           => $now,
                'resolved_by_admin_id' => $adminId,
            ])->save();

            $this->recordEvent($row, $expected->value, MassPaymentDispatchState::Settled->value, $adminId === null ? MassPaymentActor::system() : new MassPaymentActor(MassPaymentActor::TYPE_ADMIN, $adminId), 'row_paid', [
                'line_no'            => (int) $row->line_no,
                'provider_reference' => $providerReference,
            ]);

            return MassPaymentActionResult::done(null, $row);
        }, 3);
    }

    /**
     * Refund a line in flight on a definitive answer that it was not paid:
     * the provider said so, or an admin confirmed it. Anything less never
     * reaches here.
     */
    public function refundDefinitive(int $rowId, MassPaymentDispatchState $expected, MassPaymentIssue $issue, ?PayoutResult $result = null, ?string $reason = null, ?int $adminId = null): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $expected, $issue, $result, $reason, $adminId): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if (! $this->isStill($row, $expected)) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $row->forceFill([
                'provider_status'      => $this->providerStatus($result) ?? $row->provider_status,
                'last_check_outcome'   => $result === null ? $row->last_check_outcome : PayoutOutcome::Failed->value,
                'failure_reason'       => $this->failureReason($reason ?? $result?->message),
                'next_check_at'        => null,
                'resolved_by_admin_id' => $adminId,
            ])->save();

            return $this->rows->failRow($rowId, $issue, definitivelyNotPaid: true);
        }, 3);
    }

    /**
     * Keep a line waiting on its provider: AwaitingOperator for the Manual
     * driver, which has nobody to ask, otherwise AwaitingProvider with the
     * next read-only check scheduled. Never refunds.
     *
     * After the send the first check comes {@see self::FIRST_CHECK_SECONDS}
     * later; after each check (`$isCheck`) the wait doubles, up to
     * {@see self::MAX_CHECK_INTERVAL_SECONDS}, so a slow payout is not asked
     * about every minute for days.
     */
    public function awaitProvider(int $rowId, MassPaymentDispatchState $expected, PayoutOutcome $outcome, ?PayoutResult $result, ?PayoutOrderGateway $gateway = null, bool $isCheck = false): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $expected, $outcome, $result, $gateway, $isCheck): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if (! $this->isStill($row, $expected)) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $operator = $gateway !== null && ! $gateway->supportsLookup();
            $state    = $operator ? MassPaymentDispatchState::AwaitingOperator : MassPaymentDispatchState::AwaitingProvider;
            $now      = CarbonImmutable::now();
            $checks   = (int) $row->check_attempts + ($isCheck ? 1 : 0);
            $wait     = $isCheck
                ? min(self::MAX_CHECK_INTERVAL_SECONDS, self::FIRST_CHECK_SECONDS * (2 ** min($checks, 12)))
                : self::FIRST_CHECK_SECONDS;

            $row->forceFill([
                'dispatch_state'     => $state->value,
                'provider_reference' => $this->reference($result?->gatewayReference) ?? $row->provider_reference,
                'provider_status'    => $this->providerStatus($result)               ?? $row->provider_status,
                'last_check_outcome' => $outcome->value,
                'last_checked_at'    => $isCheck ? $now : $row->last_checked_at,
                'check_attempts'     => $checks,
                'next_check_at'      => $operator ? null : $now->addSeconds($wait),
            ])->save();

            if ($state !== $expected) {
                $this->recordEvent($row, $expected->value, $state->value, MassPaymentActor::system(), $operator ? 'row_awaiting_operator' : 'row_awaiting_provider', [
                    'line_no' => (int) $row->line_no,
                    'outcome' => $outcome->value,
                ]);
            }

            return MassPaymentActionResult::done(null, $row);
        }, 3);
    }

    /**
     * Note a status check that changed nothing about where the line stands:
     * what it found and when. Used for a line under review, which only an
     * admin moves on.
     */
    public function noteCheck(int $rowId, MassPaymentDispatchState $expected, PayoutOutcome $outcome): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $expected, $outcome): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if (! $this->isStill($row, $expected)) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $row->forceFill([
                'last_check_outcome' => $outcome->value,
                'last_checked_at'    => now(),
                'check_attempts'     => (int) $row->check_attempts + 1,
            ])->save();

            return MassPaymentActionResult::done(null, $row);
        }, 3);
    }

    /**
     * Send a line in flight to review: its outcome cannot be settled or
     * refunded on what the provider said. Blocks nothing else in the batch;
     * an admin decides the line.
     */
    public function toReview(int $rowId, MassPaymentDispatchState $expected, string $reason, ?PayoutResult $result = null): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $expected, $reason, $result): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if (! $this->isStill($row, $expected)) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $metadata = is_array($row->metadata) ? $row->metadata : [];

            $row->forceFill([
                'dispatch_state'  => MassPaymentDispatchState::NeedsReview->value,
                'provider_status' => $this->providerStatus($result) ?? $row->provider_status,
                'next_check_at'   => null,
                'metadata'        => array_merge($metadata, ['payout_review' => $reason]),
            ])->save();

            $this->recordEvent($row, $expected->value, MassPaymentDispatchState::NeedsReview->value, MassPaymentActor::system(), 'row_needs_review', [
                'line_no' => (int) $row->line_no,
                'reason'  => $reason,
            ]);

            Log::warning('Mass payout line needs review', [
                'mass_payment_batch_id' => (int) $row->mass_payment_batch_id,
                'mass_payment_row_id'   => (int) $row->id,
                'reason'                => $reason,
            ]);

            return MassPaymentActionResult::done(null, $row);
        }, 3);
    }

    /**
     * The order a driver is handed for this line: its request id as the
     * idempotency key, its exact net at the currency's places, and its
     * decrypted account. Built in memory only.
     */
    public function order(MassPaymentBatch $batch, MassPaymentRow $row): PayoutOrder
    {
        $currency = (string) $batch->currency_code;
        $account  = is_array($row->payout_account) ? $row->payout_account : [];

        return new PayoutOrder(
            idempotencyKey: (string) $row->provider_request_id,
            reference: (string) $row->provider_request_id,
            amount: DecimalMoney::normalize((string) $row->net_amount, Money::decimals($currency)),
            currency: $currency,
            recipient: $this->accounts->recipient(array_map(static fn (mixed $value): string => (string) $value, $account)),
            gatewayReference: $row->provider_reference,
            submittedAt: $row->submitted_at === null ? null : CarbonImmutable::instance($row->submitted_at),
        );
    }

    private function walk(int $batchId, int $rowBudget, CarbonInterface $deadline): void
    {
        $now = now();

        $started = MassPaymentBatch::query()
            ->whereKey($batchId)
            ->where('status', MassPaymentBatchStatus::Queued->value)
            ->update([
                'status'                => MassPaymentBatchStatus::Processing->value,
                'processing_started_at' => $now,
                'last_progress_at'      => $now,
            ]);

        $batch = MassPaymentBatch::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        if ($started === 1) {
            $this->recordBatchEvent($batch, MassPaymentBatchStatus::Queued->value, MassPaymentBatchStatus::Processing->value, 'processing');
        }

        if (! $this->isRunnable($batch)) {
            return;
        }

        $processed = 0;
        $operator  = 0;

        foreach ($this->reservedRows($batchId)->orderBy('id')->pluck('id') as $rowId) {
            if ($processed >= $rowBudget || now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $processed++;

            $stop = MassPaymentBatch::query()->whereKey($batchId)->first(['id', 'stop_requested_at', 'stop_requested_by_type', 'user_id']);

            if ($stop?->stop_requested_at !== null) {
                $this->rows->failRow((int) $rowId, $this->stopIssue($stop));

                continue;
            }

            if (! $this->canTransact(User::query()->find($stop?->user_id))) {
                $this->rows->hold($batchId, MassPaymentProcessor::HOLD_SENDER_UNAVAILABLE);

                break;
            }

            $result = $this->dispatchRow((int) $rowId);

            if ($result->is(MassPaymentActionResult::HELD)) {
                $this->rows->hold($batchId, self::HOLD_PROVIDER_UNAVAILABLE);

                break;
            }

            if ($result->is(MassPaymentActionResult::ANOMALY)) {
                $this->rows->hold($batchId, MassPaymentProcessor::HOLD_LEDGER_ANOMALY);
            }

            if ($result->row?->dispatchState() === MassPaymentDispatchState::AwaitingOperator) {
                $operator++;
            }

            MassPaymentBatch::query()->whereKey($batchId)->update(['last_progress_at' => now()]);
        }

        if ($operator > 0) {
            $this->announceOperatorWork($batchId);
        }

        if (! $this->pendingRows($batchId)->exists()) {
            $this->rows->finalize($batchId);
        }
    }

    /**
     * Reserved -> Submitting, committed before any provider call. A stop
     * request, a line no longer Reserved or a ledger the line disagrees with
     * is answered here and nothing is claimed.
     */
    private function claim(int $rowId): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if ($row->status()                                                                      !== MassPaymentRowStatus::Pending || $row->needs_review
                                                                                                                                      || $row->dispatchState() !== MassPaymentDispatchState::Reserved) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $debit = $row->debit_transaction_id === null
                ? null
                : Transaction::query()->lockForUpdate()->find($row->debit_transaction_id);

            if (! $this->isReservedDebit($row, $debit) || $row->provider_request_id === null) {
                return $this->flag($row, $debit);
            }

            $now     = CarbonImmutable::now();
            $maxCall = $this->maxCallSeconds((string) $row->payout_driver);

            $row->forceFill([
                'dispatch_state' => MassPaymentDispatchState::Submitting->value,
                'submitted_at'   => $now,
                'next_check_at'  => $now->addSeconds($maxCall + self::SUBMIT_GRACE_SECONDS),
                'attempts'       => min(255, (int) $row->attempts + 1),
            ])->save();

            $this->recordEvent($row, MassPaymentDispatchState::Reserved->value, MassPaymentDispatchState::Submitting->value, MassPaymentActor::system(), 'row_submitted', [
                'line_no' => (int) $row->line_no,
            ]);

            return MassPaymentActionResult::done(null, $row);
        }, 3);
    }

    /**
     * The provider refused our credentials before anything was sent: back to
     * Reserved, as if it had never been claimed, and the batch is held.
     */
    private function unclaim(int $rowId, PayoutProviderUnavailableException $exception): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $exception): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if (! $this->isStill($row, MassPaymentDispatchState::Submitting)) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $row->forceFill([
                'dispatch_state' => MassPaymentDispatchState::Reserved->value,
                'submitted_at'   => null,
                'next_check_at'  => null,
                'failure_reason' => $this->failureReason($exception->reason()),
            ])->save();

            $this->recordEvent($row, MassPaymentDispatchState::Submitting->value, MassPaymentDispatchState::Reserved->value, MassPaymentActor::system(), 'row_unclaimed', [
                'line_no' => (int) $row->line_no,
                'stage'   => $exception->stage(),
            ]);

            return MassPaymentActionResult::held(null, $row);
        }, 3);
    }

    /** Nothing reached the provider: refund the line as not sent. */
    private function notSent(int $rowId, Throwable $exception): MassPaymentActionResult
    {
        $reason = $exception instanceof PayoutNotSentException ? $exception->reason() : PayoutNotSentException::sanitise($exception->getMessage());

        return $this->refundDefinitive($rowId, MassPaymentDispatchState::Submitting, MassPaymentIssue::ProviderNotSent, null, $reason);
    }

    /**
     * Why this answer cannot be about this line, or null when it can: its
     * reference already bound to another line of the same driver, or an
     * amount or currency the provider reported that is not the line's.
     */
    private function mismatch(MassPaymentRow $row, PayoutResult $result): ?string
    {
        $reference = $this->reference($result->gatewayReference);

        if ($reference !== null) {
            $taken = MassPaymentRow::query()
                ->where('payout_driver', $row->payout_driver)
                ->where('provider_reference', $reference)
                ->whereKeyNot($row->id)
                ->exists();

            if ($taken) {
                return self::REVIEW_REFERENCE_TAKEN;
            }
        }

        if ($result->currency !== null) {
            $batchCurrency = (string) MassPaymentBatch::query()->whereKey($row->mass_payment_batch_id)->value('currency_code');

            if (strcasecmp($result->currency, $batchCurrency) !== 0) {
                return self::REVIEW_PROVIDER_MISMATCH;
            }
        }

        if ($result->amount !== null
            && (! is_numeric($result->amount) || DecimalMoney::compare($result->amount, (string) $row->net_amount) !== 0)) {
            return self::REVIEW_PROVIDER_MISMATCH;
        }

        return null;
    }

    /**
     * Flag a line whose debit is not what confirm wrote, inside the caller's
     * transaction, which commits the flag. Nothing moves; every runner skips
     * the line until an admin closes it after review.
     */
    private function flag(MassPaymentRow $row, ?Transaction $debit): MassPaymentActionResult
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];

        $row->forceFill([
            'needs_review'  => true,
            'review_reason' => MassPaymentProcessor::REVIEW_LEDGER_ANOMALY,
            'metadata'      => array_merge($metadata, [
                'ledger_anomaly' => $debit === null ? MassPaymentProcessor::ANOMALY_DEBIT_MISSING : MassPaymentProcessor::ANOMALY_DEBIT_NOT_PENDING,
                'debit_status'   => $debit?->status?->value,
            ]),
        ])->save();

        $this->recordEvent($row, (string) $row->getAttribute('dispatch_state'), (string) $row->getAttribute('dispatch_state'), MassPaymentActor::system(), 'row_flagged', [
            'line_no' => (int) $row->line_no,
        ]);

        Log::warning('Mass payment ledger anomaly', [
            'mass_payment_batch_id' => (int) $row->mass_payment_batch_id,
            'mass_payment_row_id'   => (int) $row->id,
            'transaction_id'        => $row->debit_transaction_id === null ? null : (int) $row->debit_transaction_id,
        ]);

        return MassPaymentActionResult::anomaly(null, $row);
    }

    /**
     * Tell the operators once per batch that lines wait to be paid by hand,
     * how many in total. Later walks of the same batch add nothing.
     */
    private function announceOperatorWork(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch    = MassPaymentBatch::query()->lockForUpdate()->findOrFail($batchId);
            $metadata = is_array($batch->metadata) ? $batch->metadata : [];

            if (isset($metadata['operator_notified_at'])) {
                return;
            }

            $waiting = MassPaymentRow::query()
                ->where('mass_payment_batch_id', $batchId)
                ->where('dispatch_state', MassPaymentDispatchState::AwaitingOperator->value)
                ->count();

            $batch->forceFill(['metadata' => array_merge($metadata, ['operator_notified_at' => now()->toIso8601String()])])->save();

            $this->notifier->operatorRequired($batch, $waiting);
        }, 3);
    }

    /** The debit is exactly what confirm reserved for this line: a PENDING MASS_PAYMENT of its payable. */
    private function isReservedDebit(MassPaymentRow $row, ?Transaction $debit): bool
    {
        return $debit !== null
            && $debit->trx_type                                                                      === TrxType::MASS_PAYMENT
            && $debit->status                                                                        === TrxStatus::PENDING
            && DecimalMoney::compare((string) $debit->payable_amount, (string) $row->payable_amount) === 0;
    }

    /** The line is still pending, unflagged and in the state the caller saw it in. */
    private function isStill(MassPaymentRow $row, MassPaymentDispatchState $expected): bool
    {
        return $row->status() === MassPaymentRowStatus::Pending
            && ! $row->needs_review
            && $row->dispatchState() === $expected;
    }

    /**
     * A runner may work the batch: it is processing, or a stop was requested
     * (while processing, held, or waiting for approval) and its refunds must
     * go through.
     */
    private function isRunnable(MassPaymentBatch $batch): bool
    {
        $status = $batch->status();

        return $status === MassPaymentBatchStatus::Processing
            || ($batch->stop_requested_at !== null && in_array($status, [
                MassPaymentBatchStatus::NeedsReview,
                MassPaymentBatchStatus::AwaitingApproval,
                MassPaymentBatchStatus::Queued,
            ], true));
    }

    /** @return Builder<MassPaymentRow> */
    private function reservedRows(int $batchId): Builder
    {
        return $this->pendingRows($batchId)->where('dispatch_state', MassPaymentDispatchState::Reserved->value);
    }

    /** @return Builder<MassPaymentRow> */
    private function pendingRows(int $batchId): Builder
    {
        return MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batchId)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->where('needs_review', false);
    }

    private function stopIssue(MassPaymentBatch $batch): MassPaymentIssue
    {
        return $batch->stop_requested_by_type === MassPaymentActor::TYPE_ADMIN
            ? MassPaymentIssue::CancelledByAdmin
            : MassPaymentIssue::Stopped;
    }

    private function canTransact(?User $user): bool
    {
        return $user !== null
            && $user->status === UserStatus::ACTIVE
            && ! $user->isSuspended();
    }

    /** The driver's worst-case call, or a minute when the driver cannot even be built. */
    private function maxCallSeconds(string $driver): int
    {
        try {
            return max(1, $this->gateways->massPayoutGateway($driver)->maxCallSeconds());
        } catch (Throwable) {
            return 60;
        }
    }

    private function reference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : mb_substr($reference, 0, self::PROVIDER_REFERENCE_MAX_LENGTH);
    }

    private function providerStatus(?PayoutResult $result): ?string
    {
        return $result === null ? null : mb_substr($result->status->value, 0, self::PROVIDER_STATUS_MAX_LENGTH);
    }

    private function failureReason(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return mb_substr(PayoutNotSentException::sanitise($reason), 0, self::FAILURE_REASON_MAX_LENGTH);
    }

    /**
     * Append a line event to the batch's audit trail, inside the caller's
     * transaction. A payout line's event carries its dispatch states.
     *
     * @param array<string, mixed> $payload
     */
    private function recordEvent(MassPaymentRow $row, ?string $from, string $to, MassPaymentActor $actor, string $note, array $payload = []): void
    {
        MassPaymentEvent::query()->create([
            'mass_payment_batch_id' => $row->mass_payment_batch_id,
            'row_id'                => $row->id,
            'from_status'           => $from,
            'to_status'             => $to,
            'actor_type'            => $actor->type,
            'actor_id'              => $actor->id,
            'note'                  => $note,
            'payload'               => $payload === [] ? null : $payload,
        ]);
    }

    private function recordBatchEvent(MassPaymentBatch $batch, string $from, string $to, string $note): void
    {
        MassPaymentEvent::query()->create([
            'mass_payment_batch_id' => $batch->id,
            'from_status'           => $from,
            'to_status'             => $to,
            'actor_type'            => MassPaymentActor::TYPE_SYSTEM,
            'note'                  => $note,
        ]);
    }
}
