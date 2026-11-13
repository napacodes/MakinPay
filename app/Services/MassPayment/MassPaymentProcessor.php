<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Enums\UserStatus;
use App\Exceptions\MassPaymentRecipientStateChangedException;
use App\Exceptions\NotifyErrorException;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Models\MassPaymentRow;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pays out a confirmed mass payment, one line at a time, each exactly once.
 *
 * Confirm already took the whole total from the source wallet and wrote one
 * PENDING debit per line. Every line here ends one of two ways, each in one
 * transaction that commits the balance change, both ledger writes and the
 * line's new status together:
 *
 *   paid    the recipient wallet is credited the line's net, a RECEIVE_MONEY
 *           credit is written and the debit becomes COMPLETED;
 *   failed  the source wallet is credited the line's whole payable, fee
 *           included, one REFUND is written and the debit becomes FAILED.
 *
 * Lock order, the module's own (batch, row, debit, exactly one wallet):
 *
 *   payRow    row, then debit, then the recipient wallet only
 *   failRow   row, then debit, then the source wallet only
 *   hold      the batch only
 *   finalize  the batch only, reading the rows and the ledger without locks
 *
 * payRow and failRow never lock or write the batch row, so a batch that is
 * being stopped or held never waits on a line, and no transaction ever holds
 * two wallets: a merchant paying another merchant whose batch pays the first
 * one back cannot deadlock wallet against wallet. Progress bookkeeping (the
 * Queued to Processing move, `last_progress_at`, a line's attempts) is single
 * statements outside any line transaction.
 *
 * Why a retry cannot pay twice: there is no external call; the line lock, the
 * debit's PENDING check, the existing-credit and existing-refund checks and
 * the unique ledger columns on the line back each other up, and all of them
 * commit or roll back with the money.
 *
 * Anything the ledger disagrees with - a debit missing, no longer PENDING,
 * for another amount, or already carrying a credit or a refund - flags the
 * line for review and moves nothing. Nothing resolves an anomaly on its own.
 *
 * Notifications ({@see MassPaymentNotifier}) are queued from inside these
 * transactions but only go out once they commit: the recipient of each paid
 * line, the admins when a batch is held, and the owner once, on the move to a
 * terminal state.
 */
class MassPaymentProcessor
{
    /** The cache lock every runner of one batch takes: the job, the resume command and an inline stop. */
    public const LOCK_PREFIX = 'mass-payment:process:';

    public const LOCK_SECONDS = 600;

    public const CHUNK = 100;

    /** A line whose pay attempt threw this many times is refunded as a processing error. */
    public const MAX_ATTEMPTS = 3;

    public const HOLD_SENDER_UNAVAILABLE = 'sender_unavailable';

    public const HOLD_LEDGER_ANOMALY = 'ledger_anomaly';

    /** `review_reason` on a flagged line. */
    public const REVIEW_LEDGER_ANOMALY = 'ledger_anomaly';

    public const ANOMALY_DEBIT_MISSING = 'debit_missing';

    public const ANOMALY_DEBIT_NOT_PENDING = 'debit_not_pending';

    public const ANOMALY_AMOUNT_MISMATCH = 'amount_mismatch';

    public const ANOMALY_CREDIT_EXISTS = 'credit_exists';

    public const ANOMALY_REFUND_EXISTS = 'refund_exists';

    /** `metadata.ledger_anomaly` when finalize finds the batch's ledger does not add up. */
    public const ANOMALY_CONSERVATION = 'conservation';

    private const SCALE = DecimalMoney::CALCULATION_SCALE;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly MassPaymentLedger $ledger,
        private readonly MassPaymentNotifier $notifier,
    ) {}

    /**
     * Pay one reserved line.
     *
     * It decides first, from plain reads with nothing locked or written: a
     * line no longer pending is already resolved; a batch with a stop request
     * refunds it; a sender who can no longer pay holds it (the caller holds
     * the batch); a pinned recipient or wallet that can no longer receive
     * refunds it. Only a line that passes all of that is paid, in one
     * transaction that locks the line, then its debit, then the recipient
     * wallet alone, and re-checks each on the locked copy.
     *
     * A recipient wallet that changed between the decision and the lock rolls
     * the transaction back with nothing moved, and the line is decided once
     * more from scratch, which refunds it.
     */
    public function payRow(int $rowId): MassPaymentActionResult
    {
        for ($pass = 1; ; $pass++) {
            $row = MassPaymentRow::query()->find($rowId);

            if ($row === null || $row->status() !== MassPaymentRowStatus::Pending || $row->needs_review) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            $batch = MassPaymentBatch::query()->findOrFail($row->mass_payment_batch_id);

            if ($batch->isPayout()) {
                return MassPaymentActionResult::notReady($batch, $row);
            }

            if ($batch->stop_requested_at !== null) {
                return $this->failRow($rowId, $this->stopIssue($batch));
            }

            if (! $this->canTransact(User::query()->find($batch->user_id))) {
                return MassPaymentActionResult::held($batch, $row);
            }

            $issue = $this->recipientIssue($batch, $row);

            if ($issue !== null) {
                return $this->failRow($rowId, $issue);
            }

            try {
                return DB::transaction(function () use ($rowId, $batch): MassPaymentActionResult {
                    $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

                    if ($row->status() !== MassPaymentRowStatus::Pending || $row->needs_review) {
                        return MassPaymentActionResult::alreadyResolved(null, $row);
                    }

                    $debit = $row->debit_transaction_id === null
                        ? null
                        : Transaction::query()->lockForUpdate()->find($row->debit_transaction_id);

                    $anomaly = $this->ledgerAnomaly($row, $debit);

                    if ($anomaly !== null) {
                        return $this->flagRow($row, $debit, $anomaly);
                    }

                    $recipient = Wallet::query()->lockForUpdate()->find($row->recipient_wallet_id);

                    if ($this->walletIssue($batch, $row, $recipient) !== null) {
                        throw new MassPaymentRecipientStateChangedException($rowId);
                    }

                    $recipient = $this->wallets->creditSettlement($recipient, (string) $row->net_amount);
                    $source    = Wallet::query()->with('user', 'currency')->findOrFail($batch->wallet_id);

                    $credit = Transaction::query()->create($this->ledger->creditRow($batch, $row, $debit, $source, $recipient));

                    $this->afterCredit($row, $credit);

                    $debit->forceFill([
                        'status'        => TrxStatus::COMPLETED,
                        'trx_reference' => (string) $credit->id,
                        'trx_data'      => array_merge(is_array($debit->trx_data) ? $debit->trx_data : [], [
                            'credit_trx_id' => (string) $credit->trx_id,
                        ]),
                    ])->save();

                    $row->forceFill([
                        'status'                => MassPaymentRowStatus::Paid->value,
                        'credit_transaction_id' => $credit->id,
                        'paid_at'               => now(),
                    ])->save();

                    $this->notifier->paid($credit, $source);

                    return MassPaymentActionResult::done(null, $row);
                }, 3);
            } catch (MassPaymentRecipientStateChangedException $changed) {
                if ($pass >= 2) {
                    throw $changed;
                }
            }
        }
    }

    /**
     * Refund one reserved line: its whole payable, fee included, back to the
     * source wallet, with exactly one REFUND bound to its debit.
     *
     * One transaction: the line, then its debit, then the source wallet alone.
     * A line no longer pending is already resolved; a debit the ledger
     * disagrees with, or one that already has a credit or a refund, flags the
     * line and moves nothing.
     *
     * A payout line the provider may already have been asked about
     * (submitting, with an operator, with the provider, or under review) is
     * never refunded here: it is `not_ready`, and only a definitive answer
     * from the provider or an admin's decision may release it. Such a caller
     * says so with `$definitivelyNotPaid`, which is the only way an in-flight
     * line is refunded. A payout line that is refunded ends Refunded.
     */
    public function failRow(int $rowId, MassPaymentIssue $code, bool $definitivelyNotPaid = false): MassPaymentActionResult
    {
        return DB::transaction(function () use ($rowId, $code, $definitivelyNotPaid): MassPaymentActionResult {
            $row = MassPaymentRow::query()->lockForUpdate()->findOrFail($rowId);

            if ($row->status() !== MassPaymentRowStatus::Pending || $row->needs_review) {
                return MassPaymentActionResult::alreadyResolved(null, $row);
            }

            if ($row->dispatchState()?->isInFlight() === true && ! $definitivelyNotPaid) {
                return MassPaymentActionResult::notReady(null, $row);
            }

            $batch = MassPaymentBatch::query()->findOrFail($row->mass_payment_batch_id);

            $debit = $row->debit_transaction_id === null
                ? null
                : Transaction::query()->lockForUpdate()->find($row->debit_transaction_id);

            $anomaly = $this->ledgerAnomaly($row, $debit);

            if ($anomaly !== null) {
                return $this->flagRow($row, $debit, $anomaly);
            }

            $source = Wallet::query()->whereKey($batch->wallet_id)->where('user_id', $batch->user_id)->lockForUpdate()->firstOrFail();
            $source = $this->wallets->creditSettlement($source, (string) $row->payable_amount);

            $refund = Transaction::query()->create($this->ledger->refundRow($batch, $row, $debit, $source, $code));

            $debit->forceFill([
                'status'  => TrxStatus::FAILED,
                'remarks' => $code->value,
            ])->save();

            $row->forceFill(array_merge([
                'status'                => MassPaymentRowStatus::Failed->value,
                'issue_code'            => $code->value,
                'refund_transaction_id' => $refund->id,
                'failed_at'             => now(),
            ], $row->dispatchState() === null ? [] : [
                'dispatch_state' => MassPaymentDispatchState::Refunded->value,
            ]))->save();

            $this->recordEvent($batch, MassPaymentRowStatus::Pending->value, MassPaymentRowStatus::Failed->value, $this->actorFor($batch, $code), 'row_failed', [
                'line_no'               => (int) $row->line_no,
                'reason'                => $code->value,
                'refund_transaction_id' => (int) $refund->id,
            ], (int) $row->id);

            return MassPaymentActionResult::done(null, $row);
        }, 3);
    }

    /**
     * Work through a batch's pending lines, up to `$rowBudget` of them or
     * until `$deadline`, then finalize it once no line is left to pay.
     *
     * Only one runner works a batch at a time: the job, the resume command
     * and an inline stop all take the same cache lock, and a busy lock means
     * someone else is on it. payRow is idempotent, so a duplicate would be
     * harmless anyway.
     *
     * A line whose attempt throws is left pending, because the attempt rolled
     * back with nothing moved; on its third throw it is refunded as a
     * processing error. A sender who can no longer pay holds the batch and
     * ends the walk. A ledger anomaly flags its line, holds the batch, and the
     * walk carries on with the other lines.
     */
    public function run(int $batchId, int $rowBudget, CarbonInterface $deadline): void
    {
        if ($this->isPayoutBatch($batchId)) {
            app(MassPayoutProcessor::class)->run($batchId, $rowBudget, $deadline);

            return;
        }

        $lock = Cache::lock(self::LOCK_PREFIX.$batchId, self::LOCK_SECONDS);

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
     * Whether a runner still has lines it may pay on this batch: the batch is
     * running (or held with a stop request, so the refunds can go through)
     * and has a pending line that is not flagged.
     */
    public function hasRunnableRows(int $batchId): bool
    {
        $batch = MassPaymentBatch::query()->find($batchId);

        if ($batch === null) {
            return false;
        }

        if ($batch->isPayout()) {
            return app(MassPayoutProcessor::class)->hasRunnableRows($batchId);
        }

        $runnable = in_array($batch->status(), [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)
            || $this->isRunnable($batch);

        return $runnable && $this->pendingRows($batchId)->exists();
    }

    /**
     * Hold a queued or processing batch for review, with the reason. Locks
     * the batch only. A batch already held, or past processing, is left as it
     * is.
     */
    public function hold(int $batchId, string $reason): MassPaymentActionResult
    {
        return DB::transaction(function () use ($batchId, $reason): MassPaymentActionResult {
            $batch = MassPaymentBatch::query()->lockForUpdate()->findOrFail($batchId);

            if (! in_array($batch->status(), [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)) {
                return MassPaymentActionResult::alreadyResolved($batch);
            }

            $this->toNeedsReview($batch, $reason);

            return MassPaymentActionResult::done($batch);
        }, 3);
    }

    /**
     * Close a batch whose every line has been paid, refunded, skipped or
     * closed after review. Locks the batch only.
     *
     * The counts and totals are read back from the lines and the ledger, and
     * the batch's money must add up before it closes: every debit reserved
     * equals the total, every paid line has its one completed debit and one
     * credit of its net (a paid payout line instead is Settled and has no
     * credit at all), every failed line its one failed debit and one
     * refund of its payable, and the fee revenue the dashboard reports equals
     * the paid lines' fees alone. A batch that does not add up is held with
     * `metadata.ledger_anomaly` and moves no money.
     *
     * `$afterReview` is the admin's close after review, once the flagged
     * lines are marked reviewed. A batch whose own ledger was already found
     * not to add up is then closed from its lines' figures rather than
     * refused again, since an admin has reconciled it by hand.
     */
    public function finalize(int $batchId, bool $afterReview = false): MassPaymentActionResult
    {
        return DB::transaction(function () use ($batchId, $afterReview): MassPaymentActionResult {
            $batch  = MassPaymentBatch::query()->lockForUpdate()->findOrFail($batchId);
            $status = $batch->status();

            if ($status->isTerminal()) {
                return MassPaymentActionResult::alreadyResolved($batch);
            }

            if (! $status->holdsMoney()) {
                return MassPaymentActionResult::notReady($batch);
            }

            $reconciled = $this->batchAnomaly($batch) !== null;

            if ($reconciled && ! $afterReview) {
                return MassPaymentActionResult::anomaly($batch);
            }

            $rows = MassPaymentRow::query()
                ->where('mass_payment_batch_id', $batch->id)
                ->orderBy('id')
                ->get([
                    'id', 'line_no', 'status', 'dispatch_state', 'needs_review', 'net_amount', 'payable_amount', 'wallet_fee', 'base_fee',
                    'debit_transaction_id', 'credit_transaction_id', 'refund_transaction_id',
                ]);

            $pending = $rows->filter(fn (MassPaymentRow $row): bool => $row->status() === MassPaymentRowStatus::Pending);

            if ($pending->contains(fn (MassPaymentRow $row): bool => ! $row->needs_review)) {
                return MassPaymentActionResult::notReady($batch);
            }

            if ($pending->isNotEmpty()) {
                if ($status !== MassPaymentBatchStatus::NeedsReview) {
                    $this->toNeedsReview($batch, self::HOLD_LEDGER_ANOMALY);
                }

                return MassPaymentActionResult::anomaly($batch);
            }

            $figures = $reconciled ? $this->rowFigures($rows) : $this->ledgerFigures($batch, $rows);

            if ($figures['anomaly'] !== null) {
                $metadata = is_array($batch->metadata) ? $batch->metadata : [];

                $batch->forceFill([
                    'metadata' => array_merge($metadata, [
                        'ledger_anomaly'      => self::ANOMALY_CONSERVATION,
                        'ledger_anomaly_rule' => $figures['anomaly'],
                    ]),
                ]);

                $this->toNeedsReview($batch, self::HOLD_LEDGER_ANOMALY);

                Log::warning('Mass payment ledger anomaly', [
                    'mass_payment_batch_id' => (int) $batch->id,
                    'rule'                  => $figures['anomaly'],
                ]);

                return MassPaymentActionResult::anomaly($batch);
            }

            $final = match (true) {
                $reconciled                    => MassPaymentBatchStatus::ClosedAfterReview,
                $figures['reviewed_count'] > 0 => MassPaymentBatchStatus::ClosedAfterReview,
                $figures['paid_count']   === 0 => MassPaymentBatchStatus::Failed,
                $figures['failed_count'] === 0 => MassPaymentBatchStatus::Completed,
                default                        => MassPaymentBatchStatus::PartiallyCompleted,
            };

            $batch->forceFill([
                'status'          => $final->value,
                'paid_count'      => $figures['paid_count'],
                'failed_count'    => $figures['failed_count'],
                'paid_payable'    => $figures['paid_payable'],
                'paid_base_fee'   => $figures['paid_base_fee'],
                'released_amount' => $figures['released'],
                'completed_at'    => now(),
            ])->save();

            $this->recordEvent($batch, $status->value, $final->value, MassPaymentActor::system(), 'finalized', array_filter([
                'paid_count'   => $figures['paid_count'],
                'failed_count' => $figures['failed_count'],
                'paid_payable' => $figures['paid_payable'],
                'released'     => $figures['released'],
                'from_rows'    => $reconciled ? true : null,
            ], static fn (mixed $value): bool => $value !== null));

            $this->notifier->completed($batch);

            return MassPaymentActionResult::done($batch);
        }, 3);
    }

    /**
     * Called inside the pay transaction once the credit is written and before
     * the debit and the line are settled. Does nothing; it is the seam the
     * tests use to fail a pay attempt after money has moved inside it.
     */
    protected function afterCredit(MassPaymentRow $row, Transaction $credit): void {}

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
            $this->recordEvent($batch, MassPaymentBatchStatus::Queued->value, MassPaymentBatchStatus::Processing->value, MassPaymentActor::system(), 'processing');
        }

        if (! $this->isRunnable($batch)) {
            return;
        }

        $processed = 0;

        $this->pendingRows($batchId)
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $rows) use ($batchId, $rowBudget, $deadline, &$processed): bool {
                foreach ($rows as $row) {
                    if ($processed >= $rowBudget || now()->greaterThanOrEqualTo($deadline)) {
                        $this->touchProgress($batchId);

                        return false;
                    }

                    $processed++;

                    $result = $this->processRow($batchId, (int) $row->id);

                    if ($result?->is(MassPaymentActionResult::HELD)) {
                        $this->hold($batchId, self::HOLD_SENDER_UNAVAILABLE);
                        $this->touchProgress($batchId);

                        return false;
                    }

                    if ($result?->is(MassPaymentActionResult::ANOMALY)) {
                        $this->hold($batchId, self::HOLD_LEDGER_ANOMALY);
                    }
                }

                $this->touchProgress($batchId);

                return true;
            });

        if (! $this->pendingRows($batchId)->exists()) {
            $this->finalize($batchId);
        }
    }

    /**
     * One line, as the walk sees it: refunded when a stop was requested,
     * otherwise counted as an attempt and paid. A throw leaves the line
     * pending (its transaction rolled back) and returns null, until the
     * attempt that reaches MAX_ATTEMPTS refunds it.
     */
    private function processRow(int $batchId, int $rowId): ?MassPaymentActionResult
    {
        $stop = MassPaymentBatch::query()
            ->whereKey($batchId)
            ->first(['id', 'stop_requested_at', 'stop_requested_by_type']);

        if ($stop?->stop_requested_at !== null) {
            return $this->failRow($rowId, $this->stopIssue($stop));
        }

        MassPaymentRow::query()->whereKey($rowId)->increment('attempts');

        try {
            return $this->payRow($rowId);
        } catch (Throwable $exception) {
            report($exception);

            $attempts = (int) MassPaymentRow::query()->whereKey($rowId)->value('attempts');

            if ($attempts >= self::MAX_ATTEMPTS) {
                return $this->failRow($rowId, MassPaymentIssue::ProcessingError);
            }

            return null;
        }
    }

    /**
     * The lines a runner may still pay: pending and not flagged for review.
     *
     * @return Builder<MassPaymentRow>
     */
    private function pendingRows(int $batchId): Builder
    {
        return MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batchId)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->where('needs_review', false);
    }

    /**
     * A payout batch is never walked here: its lines go to an external
     * provider, never to a wallet, so its runs are handed to
     * {@see MassPayoutProcessor}, which every caller of this processor - the
     * job, the resume command, an inline stop - therefore reaches too.
     */
    private function isPayoutBatch(int $batchId): bool
    {
        $kind = MassPaymentBatch::query()->whereKey($batchId)->value('kind');

        return $kind !== null && $kind !== MassPaymentKind::Wallet->value;
    }

    /** A runner may work the batch: it is processing, or held with a stop request whose refunds must go through. */
    private function isRunnable(MassPaymentBatch $batch): bool
    {
        $status = $batch->status();

        return $status === MassPaymentBatchStatus::Processing
            || ($status === MassPaymentBatchStatus::NeedsReview && $batch->stop_requested_at !== null);
    }

    private function touchProgress(int $batchId): void
    {
        MassPaymentBatch::query()->whereKey($batchId)->update(['last_progress_at' => now()]);
    }

    /**
     * Move a batch the caller holds locked to NeedsReview, record it, and
     * tell the admins once the move commits.
     */
    private function toNeedsReview(MassPaymentBatch $batch, string $reason): void
    {
        $from = (string) $batch->getAttribute('status');

        $batch->forceFill([
            'status'      => MassPaymentBatchStatus::NeedsReview->value,
            'hold_reason' => $reason,
        ])->save();

        $this->recordEvent($batch, $from, MassPaymentBatchStatus::NeedsReview->value, MassPaymentActor::system(), 'held', [
            'reason' => $reason,
        ]);

        $this->notifier->needsReview($batch, $reason);
    }

    /** The batch-level anomaly finalize recorded earlier, if any. */
    private function batchAnomaly(MassPaymentBatch $batch): ?string
    {
        $metadata = is_array($batch->metadata) ? $batch->metadata : [];
        $anomaly  = $metadata['ledger_anomaly'] ?? null;

        return $anomaly === null ? null : (string) $anomaly;
    }

    /** The refund reason a stop request gives: the owner's stop or an admin's cancel. */
    private function stopIssue(MassPaymentBatch $batch): MassPaymentIssue
    {
        return $batch->stop_requested_by_type === MassPaymentActor::TYPE_ADMIN
            ? MassPaymentIssue::CancelledByAdmin
            : MassPaymentIssue::Stopped;
    }

    /** Who a refund is recorded against: whoever asked for the stop, otherwise the system. */
    private function actorFor(MassPaymentBatch $batch, MassPaymentIssue $code): MassPaymentActor
    {
        if (in_array($code, [MassPaymentIssue::Stopped, MassPaymentIssue::CancelledByAdmin], true)
            && $batch->stop_requested_by_type !== null) {
            return new MassPaymentActor((string) $batch->stop_requested_by_type, $batch->stop_requested_by_id);
        }

        return MassPaymentActor::system();
    }

    /** An account that may move money: active and not suspended. */
    private function canTransact(?User $user): bool
    {
        return $user !== null
            && $user->status === UserStatus::ACTIVE
            && ! $user->isSuspended();
    }

    /**
     * Why the line's pinned recipient can no longer be paid, re-checked and
     * never re-resolved: the account must still be an active customer, the
     * wallet still theirs, in the batch currency, not the sender's, and able
     * to receive.
     */
    private function recipientIssue(MassPaymentBatch $batch, MassPaymentRow $row): ?MassPaymentIssue
    {
        $user = $row->recipient_user_id === null ? null : User::query()->find($row->recipient_user_id);

        if ($user === null || $user->system_account_type !== null || ! $this->canTransact($user)) {
            return MassPaymentIssue::RecipientUnavailable;
        }

        $wallet = $row->recipient_wallet_id === null ? null : Wallet::query()->find($row->recipient_wallet_id);

        return $this->walletIssue($batch, $row, $wallet);
    }

    private function walletIssue(MassPaymentBatch $batch, MassPaymentRow $row, ?Wallet $wallet): ?MassPaymentIssue
    {
        if ($wallet === null
            || (int) $wallet->user_id     !== (int) $row->recipient_user_id
            || (int) $wallet->currency_id !== (int) $batch->currency_id
            || (int) $row->recipient_user_id === (int) $batch->user_id) {
            return MassPaymentIssue::WalletUnavailable;
        }

        try {
            $this->wallets->assertOperational($wallet);
        } catch (NotifyErrorException) {
            return MassPaymentIssue::WalletUnavailable;
        }

        return null;
    }

    /**
     * What is wrong with the line's debit, or null when it is exactly what
     * confirm wrote: a PENDING MASS_PAYMENT debit of the line's payable that
     * no credit and no refund points at yet.
     */
    private function ledgerAnomaly(MassPaymentRow $row, ?Transaction $debit): ?string
    {
        if ($debit === null || $debit->trx_type !== TrxType::MASS_PAYMENT) {
            return self::ANOMALY_DEBIT_MISSING;
        }

        if ($debit->status !== TrxStatus::PENDING) {
            return self::ANOMALY_DEBIT_NOT_PENDING;
        }

        if (DecimalMoney::compare((string) $debit->payable_amount, (string) $row->payable_amount) !== 0) {
            return self::ANOMALY_AMOUNT_MISMATCH;
        }

        $credited = Transaction::query()
            ->where('trx_type', TrxType::RECEIVE_MONEY->value)
            ->where('trx_reference', (string) $debit->id)
            ->exists();

        if ($credited) {
            return self::ANOMALY_CREDIT_EXISTS;
        }

        $refunded = Transaction::query()
            ->where('trx_type', TrxType::REFUND->value)
            ->where('trx_reference', (string) $debit->trx_id)
            ->exists();

        return $refunded ? self::ANOMALY_REFUND_EXISTS : null;
    }

    /**
     * Flag a line whose ledger disagrees with it, inside the caller's
     * transaction, which then commits so the flag survives. Nothing moves;
     * the line stays pending and every runner skips it until an admin closes
     * it after review. The log carries ids and the code, nothing else.
     */
    private function flagRow(MassPaymentRow $row, ?Transaction $debit, string $anomaly): MassPaymentActionResult
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];

        $row->forceFill([
            'needs_review'  => true,
            'review_reason' => self::REVIEW_LEDGER_ANOMALY,
            'metadata'      => array_merge($metadata, [
                'ledger_anomaly' => $anomaly,
                'debit_status'   => $debit?->status?->value,
            ]),
        ])->save();

        $batch = MassPaymentBatch::query()->findOrFail($row->mass_payment_batch_id);

        $this->recordEvent($batch, MassPaymentRowStatus::Pending->value, MassPaymentRowStatus::Pending->value, MassPaymentActor::system(), 'row_flagged', [
            'line_no'        => (int) $row->line_no,
            'ledger_anomaly' => $anomaly,
        ], (int) $row->id);

        Log::warning('Mass payment ledger anomaly', [
            'mass_payment_batch_id' => (int) $row->mass_payment_batch_id,
            'mass_payment_row_id'   => (int) $row->id,
            'transaction_id'        => $row->debit_transaction_id === null ? null : (int) $row->debit_transaction_id,
            'anomaly'               => $anomaly,
        ]);

        return MassPaymentActionResult::anomaly(null, $row);
    }

    /**
     * The batch's figures read back from its lines and the ledger, and the
     * first conservation rule they break, if any. Exact decimal arithmetic at
     * the columns' own eight places throughout.
     *
     * @param  Collection<int, MassPaymentRow>                                                                                                                     $rows
     * @return array{paid_count: int, failed_count: int, reviewed_count: int, paid_payable: string, paid_base_fee: string, released: string, anomaly: string|null}
     */
    private function ledgerFigures(MassPaymentBatch $batch, Collection $rows): array
    {
        $ids = $rows
            ->flatMap(fn (MassPaymentRow $row): array => [$row->debit_transaction_id, $row->credit_transaction_id, $row->refund_transaction_id])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        /** @var Collection<int, Transaction> $ledger */
        $ledger = Transaction::query()->whereIn('id', $ids)->get()->keyBy('id');

        $paid     = $rows->filter(fn (MassPaymentRow $row): bool => $row->status() === MassPaymentRowStatus::Paid);
        $failed   = $rows->filter(fn (MassPaymentRow $row): bool => $row->status() === MassPaymentRowStatus::Failed);
        $reviewed = $rows->filter(fn (MassPaymentRow $row): bool => $row->status() === MassPaymentRowStatus::Reviewed);

        $figures = [
            'paid_count'     => $paid->count(),
            'failed_count'   => $failed->count(),
            'reviewed_count' => $reviewed->count(),
            'paid_payable'   => '0',
            'paid_base_fee'  => '0',
            'released'       => '0',
            'anomaly'        => null,
        ];

        $broken = function (string $rule) use (&$figures): array {
            $figures['anomaly'] = $rule;

            return $figures;
        };

        $reserved   = '0';
        $closed     = '0';
        $feeRevenue = '0';

        foreach ($rows as $row) {
            if ($row->debit_transaction_id === null) {
                continue;
            }

            $debit = $ledger->get((int) $row->debit_transaction_id);

            if ($debit === null || $debit->trx_type !== TrxType::MASS_PAYMENT) {
                return $broken('debit_missing');
            }

            $reserved = DecimalMoney::add($reserved, (string) $debit->payable_amount, self::SCALE);
        }

        if (DecimalMoney::compare($reserved, (string) $batch->total_payable) !== 0) {
            return $broken('reserved_total');
        }

        $payout = $batch->isPayout();

        foreach ($paid as $row) {
            $debit  = $ledger->get((int) $row->debit_transaction_id);
            $credit = $row->credit_transaction_id === null ? null : $ledger->get((int) $row->credit_transaction_id);

            $legsHold = $payout
                ? $this->isSettledPayout($row, $credit)
                : $this->isWalletCredit($row, $debit, $credit);

            if ($debit->status                 !== TrxStatus::COMPLETED
                || $row->refund_transaction_id !== null
                || ! $legsHold
                || DecimalMoney::compare(DecimalMoney::subtract((string) $row->payable_amount, (string) $row->net_amount, self::SCALE), (string) $row->wallet_fee) !== 0) {
                return $broken('paid_row');
            }

            $figures['paid_payable']  = DecimalMoney::add($figures['paid_payable'], (string) $row->payable_amount, self::SCALE);
            $figures['paid_base_fee'] = DecimalMoney::add($figures['paid_base_fee'], (string) $row->base_fee, self::SCALE);
            $feeRevenue               = DecimalMoney::add($feeRevenue, $this->reportedFee($debit), self::SCALE);
            $feeRevenue               = DecimalMoney::add($feeRevenue, $credit === null ? '0' : $this->reportedFee($credit), self::SCALE);
        }

        foreach ($failed as $row) {
            $debit  = $ledger->get((int) $row->debit_transaction_id);
            $refund = $row->refund_transaction_id === null ? null : $ledger->get((int) $row->refund_transaction_id);

            if ($debit->status                 !== TrxStatus::FAILED
                || $row->credit_transaction_id !== null
                || $refund === null
                || $refund->trx_type                                                              !== TrxType::REFUND
                || $refund->status                                                                !== TrxStatus::COMPLETED
                || (string) $refund->trx_reference                                                !== (string) $debit->trx_id
                || DecimalMoney::compare((string) $refund->amount, (string) $row->payable_amount) !== 0) {
                return $broken('failed_row');
            }

            $figures['released'] = DecimalMoney::add($figures['released'], (string) $refund->amount, self::SCALE);
            $feeRevenue          = DecimalMoney::add($feeRevenue, $this->reportedFee($debit), self::SCALE);
            $feeRevenue          = DecimalMoney::add($feeRevenue, $this->reportedFee($refund), self::SCALE);
        }

        foreach ($reviewed as $row) {
            $closed = DecimalMoney::add($closed, (string) $row->payable_amount, self::SCALE);
        }

        $accounted = DecimalMoney::add(DecimalMoney::add($figures['paid_payable'], $figures['released'], self::SCALE), $closed, self::SCALE);

        if (DecimalMoney::compare($accounted, (string) $batch->total_payable) !== 0) {
            return $broken('accounted_total');
        }

        if (DecimalMoney::compare($feeRevenue, $figures['paid_base_fee']) !== 0) {
            return $broken('fee_revenue');
        }

        $settled = fn (Collection $group): Collection => $group->map(fn (MassPaymentRow $row): Transaction => $ledger->get((int) $row->debit_transaction_id));

        $credits = Transaction::query()
            ->where('trx_type', TrxType::RECEIVE_MONEY->value)
            ->whereIn('trx_reference', $settled($paid->concat($failed))->map(fn (Transaction $debit): string => (string) $debit->id)->values())
            ->count();

        $refunds = Transaction::query()
            ->where('trx_type', TrxType::REFUND->value)
            ->whereIn('trx_reference', $settled($paid->concat($failed))->map(fn (Transaction $debit): string => (string) $debit->trx_id)->values())
            ->count();

        if ($credits !== ($payout ? 0 : $figures['paid_count']) || $refunds !== $figures['failed_count']) {
            return $broken('exactly_once');
        }

        return $figures;
    }

    /**
     * A paid wallet line: one COMPLETED RECEIVE_MONEY credit of its net,
     * bound to its debit.
     */
    private function isWalletCredit(MassPaymentRow $row, Transaction $debit, ?Transaction $credit): bool
    {
        return $credit !== null
            && $credit->trx_type                                                              === TrxType::RECEIVE_MONEY
            && $credit->status                                                                === TrxStatus::COMPLETED
            && (string) $credit->trx_reference                                                === (string) $debit->id
            && DecimalMoney::compare((string) $credit->net_amount, (string) $row->net_amount) === 0;
    }

    /**
     * A paid payout line: Settled with its provider, and no wallet credit at
     * all, since its money left the platform rather than landing in a
     * wallet.
     */
    private function isSettledPayout(MassPaymentRow $row, ?Transaction $credit): bool
    {
        return $credit                     === null
            && $row->credit_transaction_id === null
            && $row->dispatchState()       === MassPaymentDispatchState::Settled;
    }

    /**
     * The batch's figures from its lines alone, for a batch whose ledger an
     * admin has reconciled by hand after it was found not to add up: paid
     * lines' payable and fees, and failed lines' payable as the release.
     *
     * @param  Collection<int, MassPaymentRow>                                                                                                                     $rows
     * @return array{paid_count: int, failed_count: int, reviewed_count: int, paid_payable: string, paid_base_fee: string, released: string, anomaly: string|null}
     */
    private function rowFigures(Collection $rows): array
    {
        $figures = [
            'paid_count'     => 0,
            'failed_count'   => 0,
            'reviewed_count' => 0,
            'paid_payable'   => '0',
            'paid_base_fee'  => '0',
            'released'       => '0',
            'anomaly'        => null,
        ];

        foreach ($rows as $row) {
            $status = $row->status();

            if ($status === MassPaymentRowStatus::Paid) {
                $figures['paid_count']++;
                $figures['paid_payable']  = DecimalMoney::add($figures['paid_payable'], (string) $row->payable_amount, self::SCALE);
                $figures['paid_base_fee'] = DecimalMoney::add($figures['paid_base_fee'], (string) $row->base_fee, self::SCALE);
            } elseif ($status === MassPaymentRowStatus::Failed) {
                $figures['failed_count']++;
                $figures['released'] = DecimalMoney::add($figures['released'], (string) $row->payable_amount, self::SCALE);
            } elseif ($status === MassPaymentRowStatus::Reviewed) {
                $figures['reviewed_count']++;
            }
        }

        return $figures;
    }

    /**
     * The fee a row contributes to the dashboard's fee revenue: its base_fee
     * when COMPLETED, as LedgerReporting reads it, and nothing otherwise.
     */
    private function reportedFee(Transaction $transaction): string
    {
        if ($transaction->status !== TrxStatus::COMPLETED) {
            return '0';
        }

        return (string) ($transaction->base_fee ?? $transaction->fee ?? '0');
    }

    /**
     * Append one row to the batch's audit trail, inside the caller's
     * transaction when there is one. A line event carries the line's own
     * statuses and its id.
     *
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        MassPaymentBatch $batch,
        ?string $fromStatus,
        string $toStatus,
        MassPaymentActor $actor,
        string $note,
        array $payload = [],
        ?int $rowId = null,
    ): MassPaymentEvent {
        return MassPaymentEvent::query()->create([
            'mass_payment_batch_id' => $batch->id,
            'row_id'                => $rowId,
            'from_status'           => $fromStatus,
            'to_status'             => $toStatus,
            'actor_type'            => $actor->type,
            'actor_id'              => $actor->id,
            'note'                  => $note,
            'payload'               => $payload === [] ? null : $payload,
        ]);
    }
}
