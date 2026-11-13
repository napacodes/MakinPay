<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\FeeMode;
use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Enums\MassPayment\MassPaymentWarning;
use App\Enums\MassPayment\MassPayoutApproval;
use App\Enums\Payout\PayoutOutcome;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MassPaymentChangedException;
use App\Exceptions\MassPaymentNotConfirmableException;
use App\Exceptions\MassPayoutMethodUnavailableException;
use App\Exceptions\NotifyErrorException;
use App\Jobs\ProcessMassPaymentBatch;
use App\Models\Admin;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentRow;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawMethod;
use App\Models\WithdrawSchedule;
use App\Services\FeatureManager;
use App\Services\MassPayment\Concerns\SharesBatchSteps;
use App\Services\Money\WalletPinGuard;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\WalletService;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Mass payouts: one merchant paying many external accounts, through one
 * withdraw method, from one source wallet.
 *
 * The batch is a {@see MassPaymentBatch} of kind Payout, sharing the wallet
 * batches' tables, caps and lifecycle. This part builds, discards and
 * confirms drafts, and approves or rejects a confirmed batch that waits for
 * an admin; sending the lines lives in the payout processor.
 *
 * A draft is the file or the manual grid read against the method's own
 * account fields, every line's details checked offline by the method's
 * driver, its amount priced exactly as one withdrawal through that method
 * would be ({@see MassPayoutPricer}), and its figures frozen for the preview.
 * Building one moves no money and calls no provider.
 *
 * Each line's account details are written only to the row's encrypted
 * `payout_account`. What a preview, a report or a log shows is the masked
 * label and, for duplicate detection, a keyed hash
 * ({@see MassPayoutAccount}).
 *
 * The terms the batch was priced on - the method, its driver, currency, rate
 * and the fee mode - are frozen into `metadata.payout`, so what the batch was
 * built on never depends on a withdraw method that is edited or deleted later.
 *
 * Lock order is the module's own (batch, row, debit, exactly one wallet; see
 * {@see MassPaymentService}). createDraft takes no lock at all; discard and
 * approve lock the batch only; confirm locks the batch, then the source
 * wallet; reject locks the batch, then works each line through
 * {@see MassPaymentProcessor::failRow()} in that same order.
 */
class MassPayoutService
{
    use SharesBatchSteps;

    /** The module key, in the feature catalog (layer A) and per user (layer B). */
    public const FEATURE_KEY = 'mass_payout';

    /** `recipient_type` on every payout line. */
    public const RECIPIENT_TYPE = 'account';

    private const AMOUNT_MAX_LENGTH = 64;

    private const REFERENCE_MAX_LENGTH = 100;

    private const CURRENCY_MAX_LENGTH = 10;

    private const LABEL_MAX_LENGTH = 191;

    private const FILENAME_MAX_LENGTH = 191;

    private const INSERT_CHUNK = 250;

    /** @var list<string> */
    private const FIGURES = ['entered_amount', 'base_amount', 'base_fee', 'net_amount', 'payable_amount', 'wallet_fee'];

    /**
     * Issues that depend only on what was typed, or on a cut the draft made
     * to it, and so are kept as they are at confirm. A note over the limit was
     * stored cut to the limit; reading it back must not make the line valid.
     *
     * @var list<MassPaymentIssue>
     */
    private const KEPT_ISSUES = [MassPaymentIssue::NoteTooLong];

    /**
     * The row columns a confirm-time refresh rewrites: everything the
     * re-validation can change. What the customer typed never changes.
     *
     * @var list<string>
     */
    private const REFRESHED_COLUMNS = [
        'recipient_input',
        'recipient_label',
        'entered_amount',
        'base_amount',
        'base_fee',
        'net_amount',
        'payable_amount',
        'wallet_fee',
        'exchange_rate',
        'status',
        'issue_code',
        'warnings',
        'metadata',
        'payout_driver',
        'payout_account_hash',
    ];

    /**
     * How recent a provider check that found nothing must be before an admin
     * may mark a line under review as not paid.
     */
    public const NOT_PAID_LOOKUP_MINUTES = 15;

    private const ADMIN_NOTE_MAX_LENGTH = 2000;

    private const EVENT_TEXT_MAX_LENGTH = 500;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly MassPaymentService $walletBatches,
        private readonly MassPaymentCsvParser $parser,
        private readonly MassPayoutMethods $methods,
        private readonly MassPayoutPricer $pricer,
        private readonly MassPayoutAccount $accounts,
        private readonly WalletPinGuard $pins,
        private readonly FeatureManager $features,
        private readonly MassPaymentLedger $ledger,
        private readonly MassPaymentProcessor $processor,
        private readonly MassPaymentNotifier $notifier,
        private readonly MassPayoutProcessor $payouts,
        private readonly MassPayoutReconciler $reconciler,
        private readonly PayoutGatewayFactory $gateways,
    ) {}

    /**
     * Build a payout draft from an uploaded file (`csv`) or the manual grid
     * (`manual`) against one of the user's wallets and one eligible withdraw
     * method. No money moves, no PIN is asked and no provider is called; the
     * draft expires after {@see MassPaymentService::DRAFT_TTL_HOURS} hours.
     *
     * @param array<int|string, mixed>|UploadedFile $input
     *
     * @throws ValidationException                  for a file or grid that cannot be read, or one over the row cap
     * @throws NotifyErrorException                 when the wallet cannot pay out
     * @throws MassPayoutMethodUnavailableException when the method is not available to it
     * @throws ModelNotFoundException               when the wallet is not the user's
     */
    public function createDraft(User $user, string $walletUuid, int $methodId, array|UploadedFile $input, string $source, string $channel, ?string $name = null): MassPaymentBatch
    {
        if (! in_array($channel, [MassPaymentService::CHANNEL_WEB, MassPaymentService::CHANNEL_API], true)) {
            throw new InvalidArgumentException("Unknown mass payout channel [{$channel}].");
        }

        $isFile = match ($source) {
            MassPaymentService::SOURCE_CSV    => true,
            MassPaymentService::SOURCE_MANUAL => false,
            default                           => throw new InvalidArgumentException("Unknown mass payout source [{$source}]."),
        };

        if ($isFile !== $input instanceof UploadedFile) {
            throw new InvalidArgumentException('A csv draft takes an uploaded file and a manual draft takes rows.');
        }

        $wallet = $user->wallets()->where('uuid', $walletUuid)->with('currency')->firstOrFail();
        $this->wallets->assertOperational($wallet);

        $method = $this->methods->findForWallet($user, $wallet, $methodId);

        if ($method === null) {
            throw new MassPayoutMethodUnavailableException(__('This payout method is not available for mass payouts from this wallet.'));
        }

        $fields = $this->accounts->fieldNames($method);

        $lines = $input instanceof UploadedFile
            ? $this->parser->parsePayout($input, $this->walletBatches->maxRows(), $fields, $this->accounts->requiredFieldNames($method))
            : $this->parser->payoutFromRows($input, $this->walletBatches->maxRows(), $fields);

        $feeMode     = FeeMode::withdraw();
        $rows        = $this->evaluateLines($user, $wallet, $method, $lines, $feeMode);
        $totals      = $this->totals($rows, (string) $wallet->currency->code);
        $fingerprint = $this->fingerprint((int) $wallet->id, (int) $wallet->currency_id, (int) $method->id, $feeMode, $rows);

        return DB::transaction(function () use ($user, $wallet, $method, $input, $source, $channel, $rows, $totals, $fingerprint, $feeMode, $name): MassPaymentBatch {
            $batch = MassPaymentBatch::query()->create(array_merge([
                'uuid'                => (string) Str::uuid(),
                'reference'           => MassPaymentBatch::generateReference(),
                'name'                => MassPaymentService::batchName($name),
                'user_id'             => $user->id,
                'wallet_id'           => $wallet->id,
                'currency_id'         => $wallet->currency_id,
                'currency_code'       => (string) $wallet->currency->code,
                'kind'                => MassPaymentKind::Payout->value,
                'withdraw_method_id'  => $method->id,
                'payout_driver'       => (string) $method->mass_payout_driver,
                'source'              => $source,
                'channel'             => $channel,
                'original_filename'   => $input instanceof UploadedFile ? $this->safeFilename($input) : null,
                'status'              => MassPaymentBatchStatus::Draft->value,
                'preview_fingerprint' => $fingerprint,
                'expires_at'          => now()->addHours(MassPaymentService::DRAFT_TTL_HOURS),
                'metadata'            => ['payout' => $this->terms($method, $feeMode)],
            ], $totals));

            $now = now();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                MassPaymentRow::query()->insert(array_map(
                    fn (array $row): array => $this->insertable($batch, $row, $now),
                    $chunk,
                ));
            }

            $this->recordEvent($batch, null, MassPaymentActor::user($user), 'created', [
                'row_count'     => $batch->row_count,
                'invalid_count' => $batch->invalid_count,
                'method_id'     => (int) $method->id,
                'driver'        => (string) $method->mass_payout_driver,
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Discard one of the user's payout drafts. Discarding an already
     * discarded batch returns it unchanged; anything past Draft is refused.
     *
     * @throws NotifyErrorException   when the batch is no longer a draft
     * @throws ModelNotFoundException for another user's batch or a wallet batch
     */
    public function discard(User $user, string $uuid): MassPaymentBatch
    {
        $batch = $user->massPaymentBatches()->ofKind(MassPaymentKind::Payout)->where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($user, $batch): MassPaymentBatch {
            $locked = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Payout)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $locked->status();

            if ($status === MassPaymentBatchStatus::Discarded) {
                return $locked;
            }

            if ($status !== MassPaymentBatchStatus::Draft) {
                throw new NotifyErrorException(__('Only a draft batch can be discarded.'));
            }

            $locked->forceFill(['status' => MassPaymentBatchStatus::Discarded->value])->save();

            $this->recordEvent($locked, MassPaymentBatchStatus::Draft->value, MassPaymentActor::user($user), 'discarded');

            return $locked;
        }, 3);
    }

    /**
     * Confirm one of the user's payout drafts: reserve its total from the
     * source wallet, written as one PENDING debit per valid line with the
     * line Reserved, and either queue it or hold it for an admin's approval.
     *
     * Phase 0. The wallet PIN is checked before the batch is even looked up,
     * then both per-user features (withdraw, mass_payout) and the module's
     * own access rule. Withdrawals switched off for today refuse it too.
     *
     * Phase 1, with no transaction open. The batch is found in the owner's
     * scope, Payout kind only. A batch past Draft is a replay and debits
     * nothing. The method must still be one this wallet may use, under the
     * driver the draft was priced for; every line is re-validated and
     * re-priced under the current withdraw fee mode, and a result that
     * differs from the stored preview refreshes the draft and sends the
     * customer back to review it. The fingerprint and total the client
     * reviewed must match, and the GF4 gates and caps apply.
     *
     * Phase 2, one transaction: lock the batch and re-check Draft, recompute
     * the fingerprint and the total from the stored rows, lock the wallet,
     * debit it with the balance check in the same statement, and only then
     * insert one debit per valid line, in line order, each line given its
     * provider request id. A short balance throws before anything is
     * written. Whether the batch waits for approval is decided here, from
     * {@see MassPayoutApproval}, and recorded on the batch.
     *
     * Phase 3, outside the transaction: a batch queued by this call is
     * handed to the processor after commit; one awaiting approval is
     * announced to the approving admins after commit. A replay does neither.
     *
     * @throws NotifyErrorException               wrong or missing PIN, a feature switched off, withdrawals off today, a wallet that cannot pay or a method no longer available
     * @throws InsufficientBalanceException       the wallet holds less than the total
     * @throws MassPaymentChangedException        the batch is not the one the customer reviewed
     * @throws MassPaymentNotConfirmableException a gate refused it
     * @throws ModelNotFoundException             another user's batch, or a wallet batch
     */
    public function confirm(User $user, string $uuid, MassPaymentConfirmation $confirmation): ConfirmedMassPayment
    {
        $this->pins->assert($user, $confirmation->pin);
        $this->assertFeatureAvailable($user);

        $batch = $this->findBatchForConfirm($user, $uuid);

        if ($batch->status() !== MassPaymentBatchStatus::Draft) {
            return $this->replayOrRefuse($batch);
        }

        if ($batch->expires_at !== null && $batch->expires_at->isPast()) {
            return $this->expire($user, $batch);
        }

        if (! WithdrawSchedule::isWithdrawEnabledToday()) {
            throw new NotifyErrorException(__('Withdrawals are not enabled for today.'), 422);
        }

        $wallet = $user->wallets()->whereKey($batch->wallet_id)->with('currency')->firstOrFail();
        $this->wallets->assertOperational($wallet);

        $method  = $this->methodFor($user, $wallet, $batch);
        $feeMode = FeeMode::withdraw();

        $stored           = $batch->rows()->get();
        $fresh            = $this->evaluateLines($user, $wallet, $method, $this->linesOf($stored), $feeMode, $this->keptIssuesOf($stored));
        $freshFingerprint = $this->fingerprint((int) $wallet->id, (int) $wallet->currency_id, (int) $method->id, $feeMode, $fresh);

        if (! hash_equals((string) $batch->preview_fingerprint, $freshFingerprint)) {
            $refreshed = $this->refreshDraft($user, $batch, $wallet, $method, $feeMode, $fresh, $freshFingerprint);

            if ($refreshed === null) {
                return $this->replayOrRefuse($batch->refresh());
            }

            throw new MassPaymentChangedException($refreshed);
        }

        if (! $this->matchesPreview($confirmation, (string) $batch->preview_fingerprint, (string) $batch->total_payable)) {
            throw new MassPaymentChangedException($batch);
        }

        $this->assertConfirmable(
            $batch,
            $confirmation,
            (int) $batch->row_count,
            (int) $batch->valid_count,
            (int) $batch->invalid_count,
            (int) $batch->warning_count,
            (string) $batch->total_base_amount,
        );

        $actor    = MassPaymentActor::user($user);
        $replayed = false;

        $confirmed = DB::transaction(function () use ($user, $batch, $method, $feeMode, $confirmation, $actor, &$replayed): MassPaymentBatch {
            $replayed = false;

            $draft = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Payout)
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->status() !== MassPaymentBatchStatus::Draft) {
                $this->refuseUnlessConfirmed($draft);
                $replayed = true;

                return $draft;
            }

            $rows = MassPaymentRow::query()
                ->where('mass_payment_batch_id', $draft->id)
                ->orderBy('line_no')
                ->get();

            $summary = $this->totals($this->figuresOf($rows), (string) $draft->currency_code);
            $total   = (string) $summary['total_payable'];

            if (! $this->matchesPreview($confirmation, $this->fingerprint((int) $draft->wallet_id, (int) $draft->currency_id, (int) $method->id, $feeMode, $rows), $total)
                || DecimalMoney::compare($total, (string) $draft->total_payable) !== 0) {
                throw new MassPaymentChangedException($draft);
            }

            $this->assertConfirmable(
                $draft,
                $confirmation,
                (int) $summary['row_count'],
                (int) $summary['valid_count'],
                (int) $summary['invalid_count'],
                (int) $summary['warning_count'],
                (string) $summary['total_base_amount'],
            );

            $locked = Wallet::query()
                ->whereKey($draft->wallet_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->wallets->assertOperational($locked);

            if ($this->wallets->tryDebit($locked, $total) !== 1) {
                throw new InsufficientBalanceException(__('Insufficient wallet balance.'));
            }

            $locked->load('currency');

            $debited = 0;
            $rate    = null;

            foreach ($rows as $row) {
                if ($row->status() !== MassPaymentRowStatus::Valid) {
                    continue;
                }

                $row->forceFill([
                    'provider_request_id' => $this->providerRequestId($draft, $row),
                    'dispatch_state'      => MassPaymentDispatchState::Reserved->value,
                    'payout_driver'       => (string) $method->mass_payout_driver,
                ]);

                $debit = Transaction::query()->create($this->ledger->debitRow($draft, $row, $locked));

                $row->forceFill([
                    'status'               => MassPaymentRowStatus::Pending->value,
                    'debit_transaction_id' => $debit->id,
                ])->save();

                $debited++;
                $rate ??= $row->exchange_rate;
            }

            $now     = now();
            $skipped = 0;

            if ($confirmation->skipInvalid) {
                $skipped = MassPaymentRow::query()
                    ->where('mass_payment_batch_id', $draft->id)
                    ->where('status', MassPaymentRowStatus::Invalid->value)
                    ->update(['status' => MassPaymentRowStatus::Skipped->value, 'updated_at' => $now]);
            }

            $needsApproval = $this->needsApproval($summary);
            $status        = $needsApproval ? MassPaymentBatchStatus::AwaitingApproval : MassPaymentBatchStatus::Queued;

            $draft->forceFill([
                'status'                => $status->value,
                'approval_required'     => $needsApproval,
                'confirmed_at'          => $now,
                'exchange_rate'         => $rate ?? DecimalMoney::rate((string) $method->conversion_rate),
                'skip_invalid'          => $confirmation->skipInvalid,
                'warnings_acknowledged' => $confirmation->acknowledgeWarnings,
                'last_progress_at'      => $now,
            ])->save();

            $this->recordEvent($draft, MassPaymentBatchStatus::Draft->value, $actor, 'confirmed', [
                'debited_rows'      => $debited,
                'skipped_rows'      => $skipped,
                'total_payable'     => $total,
                'channel'           => $confirmation->channel,
                'approval_required' => $needsApproval,
                'approval_mode'     => MassPayoutApproval::fromSetting()->value,
            ]);

            if ($needsApproval) {
                $this->notifier->approvalRequired($draft);
            }

            return $draft;
        }, 3);

        if (! $replayed && $confirmed->status() === MassPaymentBatchStatus::Queued) {
            ProcessMassPaymentBatch::dispatch((int) $confirmed->id)->afterCommit();
        }

        return new ConfirmedMassPayment($confirmed->refresh(), $replayed);
    }

    /**
     * Approve a payout batch waiting for approval: it is queued and the
     * processor picks it up once that commits. Locks the batch only and moves
     * no money. The caller checks the `mass-payment-approve` permission.
     *
     * A batch already approved, rejected or finished is `already_resolved`.
     *
     * @throws NotifyErrorException the batch is not waiting for approval
     */
    public function approve(MassPaymentBatch $batch, Admin $admin): MassPaymentActionResult
    {
        $result = DB::transaction(function () use ($batch, $admin): MassPaymentActionResult {
            $locked = MassPaymentBatch::query()->whereKey($batch->id)->ofKind(MassPaymentKind::Payout)->lockForUpdate()->firstOrFail();
            $status = $locked->status();

            if ($status->isTerminal() || $locked->approved_at !== null || $locked->stop_requested_at !== null) {
                return MassPaymentActionResult::alreadyResolved($locked);
            }

            if ($status !== MassPaymentBatchStatus::AwaitingApproval) {
                throw new NotifyErrorException(__('Only a mass payout waiting for approval can be approved.'), 409);
            }

            $locked->forceFill([
                'status'               => MassPaymentBatchStatus::Queued->value,
                'approved_by_admin_id' => $admin->getKey(),
                'approved_at'          => now(),
                'last_progress_at'     => now(),
            ])->save();

            $this->recordEvent($locked, MassPaymentBatchStatus::AwaitingApproval->value, MassPaymentActor::admin($admin), 'approved');

            return MassPaymentActionResult::done($locked);
        }, 3);

        if ($result->is(MassPaymentActionResult::DONE)) {
            ProcessMassPaymentBatch::dispatch((int) $result->batch->id)->afterCommit();
        }

        return new MassPaymentActionResult($result->outcome, $result->batch->refresh());
    }

    /**
     * Reject a payout batch waiting for approval, with a reason: every
     * reserved line is refunded in full, fee included, as
     * `rejected_by_admin`, and the batch is finalized. Nothing was ever sent,
     * since a batch waiting for approval never reaches a provider.
     *
     * One transaction, in the module's lock order: the batch, then each line
     * with its debit and the source wallet through
     * {@see MassPaymentProcessor::failRow()}, then the finalize. The stop
     * request recorded first means no runner can send a line of it even
     * should the transaction fail part-way and be retried. A batch already
     * resolved is `already_resolved` and is not rejected twice.
     *
     * @throws NotifyErrorException no reason, or the batch is not waiting for approval
     */
    public function reject(MassPaymentBatch $batch, Admin $admin, string $reason): MassPaymentActionResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new NotifyErrorException(__('Give a reason for rejecting this mass payout.'), 422);
        }

        $actor = MassPaymentActor::admin($admin);

        $result = DB::transaction(function () use ($batch, $admin, $actor, $reason): MassPaymentActionResult {
            $locked = MassPaymentBatch::query()->whereKey($batch->id)->ofKind(MassPaymentKind::Payout)->lockForUpdate()->firstOrFail();
            $status = $locked->status();

            if ($status->isTerminal() || $locked->approved_at !== null || $locked->stop_requested_at !== null) {
                return MassPaymentActionResult::alreadyResolved($locked);
            }

            if ($status !== MassPaymentBatchStatus::AwaitingApproval) {
                throw new NotifyErrorException(__('Only a mass payout waiting for approval can be rejected.'), 409);
            }

            $locked->forceFill([
                'stop_requested_at'      => now(),
                'stop_requested_by_type' => $actor->type,
                'stop_requested_by_id'   => $actor->id,
                'reviewed_by_admin_id'   => $admin->getKey(),
                'admin_note'             => mb_substr($reason, 0, self::ADMIN_NOTE_MAX_LENGTH),
            ])->save();

            $this->recordEvent($locked, $status->value, $actor, 'rejected', [
                'reason' => mb_substr($reason, 0, self::EVENT_TEXT_MAX_LENGTH),
            ]);

            $pending = MassPaymentRow::query()
                ->where('mass_payment_batch_id', $locked->id)
                ->where('status', MassPaymentRowStatus::Pending->value)
                ->orderBy('id')
                ->pluck('id');

            foreach ($pending as $rowId) {
                $this->processor->failRow((int) $rowId, MassPaymentIssue::RejectedByAdmin);
            }

            $this->processor->finalize((int) $locked->id);

            return MassPaymentActionResult::done($locked);
        }, 3);

        return new MassPaymentActionResult($result->outcome, $result->batch->refresh());
    }

    /**
     * Stop one of the owner's confirmed payout batches: every line not yet
     * handed to its provider is refunded in full as `stopped`, and lines
     * already with their provider finish there, paid or refunded on its
     * answer. A batch still waiting for approval may be stopped too, which
     * refunds all of it.
     *
     * Only the batch row is locked, only to record the request; the refunds
     * then run inline, each line in its own transaction, and whatever the
     * inline run leaves is finished by the job or the resume command.
     *
     * @throws NotifyErrorException   the batch cannot be stopped in its state
     * @throws ModelNotFoundException another user's batch, or a wallet batch
     */
    public function stop(User $user, string $uuid): MassPaymentActionResult
    {
        $batch = $user->massPaymentBatches()->ofKind(MassPaymentKind::Payout)->where('uuid', $uuid)->firstOrFail();
        $actor = MassPaymentActor::user($user);

        $result = DB::transaction(function () use ($user, $batch, $actor): MassPaymentActionResult {
            $locked = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Payout)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $locked->status();

            if ($status->isTerminal() || $locked->stop_requested_at !== null) {
                return MassPaymentActionResult::alreadyResolved($locked);
            }

            if (! in_array($status, [MassPaymentBatchStatus::AwaitingApproval, MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)) {
                throw new NotifyErrorException(__('This mass payout cannot be stopped now.'), 409);
            }

            $locked->forceFill([
                'stop_requested_at'      => now(),
                'stop_requested_by_type' => $actor->type,
                'stop_requested_by_id'   => $actor->id,
            ])->save();

            $this->recordEvent($locked, $status->value, $actor, 'stop_requested');

            return MassPaymentActionResult::done($locked);
        }, 3);

        if ($result->batch->stop_requested_at !== null && ! $result->batch->status()->isTerminal()) {
            $this->processor->run((int) $result->batch->id, MassPaymentService::STOP_ROW_BUDGET, now()->addSeconds(MassPaymentService::STOP_RUN_SECONDS));
        }

        return new MassPaymentActionResult($result->outcome, $result->batch->refresh());
    }

    /**
     * Record a payout line as paid on an admin's word, with the provider's or
     * the bank's reference: a line an operator paid by hand, or one under
     * review the admin has confirmed paid outside the system. Settles it
     * exactly as a provider's confirmation would, moving no balance.
     *
     * A reference already bound to another line of the same driver is
     * refused, so one real payment can never settle two lines.
     *
     * @throws NotifyErrorException the line is not waiting on an operator or a review, or the reference is missing or taken
     */
    public function markRowPaid(MassPaymentRow $row, Admin $admin, string $reference): MassPaymentActionResult
    {
        $reference = trim($reference);
        $state     = $this->resolvableState($row);

        if ($reference === '') {
            throw new NotifyErrorException(__('Enter the reference of the payment that was made.'), 422);
        }

        $taken = MassPaymentRow::query()
            ->where('payout_driver', $row->payout_driver)
            ->where('provider_reference', $reference)
            ->whereKeyNot($row->id)
            ->exists();

        if ($taken) {
            throw new NotifyErrorException(__('This reference is already recorded on another payout line.'), 422);
        }

        $result = $this->payouts->settle((int) $row->id, $state, null, $reference, (int) $admin->getKey());

        $this->finalizeIfDone((int) $row->mass_payment_batch_id);

        return $result;
    }

    /**
     * Record a payout line as not paid on an admin's word, with a reason,
     * and refund it in full.
     *
     * A line with an operator was never sent anywhere but to that operator,
     * so their word is enough (`payout_rejected`). A line under review may
     * have reached a provider, so for a driver that can be asked, the
     * provider must have been asked within the last
     * {@see self::NOT_PAID_LOOKUP_MINUTES} minutes and found nothing
     * (`confirmed_not_paid`); otherwise the admin is told to check first.
     *
     * @throws NotifyErrorException the line cannot be resolved, there is no reason, or the provider was not checked recently
     */
    public function markRowNotPaid(MassPaymentRow $row, Admin $admin, string $reason): MassPaymentActionResult
    {
        $reason = trim($reason);
        $state  = $this->resolvableState($row);

        if ($reason === '') {
            throw new NotifyErrorException(__('Give a reason for marking this payout as not paid.'), 422);
        }

        $issue = $state === MassPaymentDispatchState::AwaitingOperator ? MassPaymentIssue::PayoutRejected : MassPaymentIssue::ConfirmedNotPaid;

        if ($state === MassPaymentDispatchState::NeedsReview && $this->supportsLookup($row) && ! $this->recentlyNotFound($row)) {
            throw new NotifyErrorException(__('Ask the provider about this payout first. It can be marked not paid only after a check finds no payment.'), 409);
        }

        $result = $this->payouts->refundDefinitive((int) $row->id, $state, $issue, null, $reason, (int) $admin->getKey());

        $this->finalizeIfDone((int) $row->mass_payment_batch_id);

        return $result;
    }

    /**
     * Ask the provider about one payout line now, on an admin's request,
     * read-only. The answer is recorded as any check's would be; see
     * {@see MassPayoutReconciler::checkNow()}.
     *
     * @throws NotifyErrorException the driver cannot be asked, or the line is not with a provider
     */
    public function checkRow(MassPaymentRow $row): PayoutOutcome
    {
        if (! $this->supportsLookup($row)) {
            throw new NotifyErrorException(__('This payout method has no provider to ask. Record the payout by hand instead.'), 409);
        }

        $outcome = $this->reconciler->checkNow((int) $row->id);

        $this->finalizeIfDone((int) $row->mass_payment_batch_id);

        return $outcome;
    }

    /**
     * The decrypted account details of one payout line, for an admin who
     * has to pay or trace it by hand. Every reveal is written to the batch's
     * audit trail, naming the admin and the line, never the details.
     *
     * @return array<string, string>
     */
    public function revealAccount(MassPaymentRow $row, Admin $admin): array
    {
        $batch = MassPaymentBatch::query()->whereKey($row->mass_payment_batch_id)->ofKind(MassPaymentKind::Payout)->firstOrFail();

        $this->recordEvent($batch, (string) $batch->getAttribute('status'), MassPaymentActor::admin($admin), 'account_revealed', [
            'line_no' => (int) $row->line_no,
        ], (int) $row->id);

        $account = is_array($row->payout_account) ? $row->payout_account : [];

        return array_map(static fn (mixed $value): string => (string) $value, $account);
    }

    /**
     * The dispatch state an admin may resolve a line from: with an operator,
     * or under review.
     *
     * @throws NotifyErrorException for any other line
     */
    private function resolvableState(MassPaymentRow $row): MassPaymentDispatchState
    {
        $state = $row->dispatchState();

        if ($row->status() !== MassPaymentRowStatus::Pending
            || $row->needs_review
            || ! in_array($state, [MassPaymentDispatchState::AwaitingOperator, MassPaymentDispatchState::NeedsReview], true)) {
            throw new NotifyErrorException(__('Only a payout waiting for an operator or under review can be recorded by hand.'), 409);
        }

        return $state;
    }

    private function supportsLookup(MassPaymentRow $row): bool
    {
        try {
            return $this->gateways->massPayoutGateway((string) $row->payout_driver)->supportsLookup();
        } catch (Throwable) {
            return false;
        }
    }

    /** The provider was asked about the line in the last few minutes and held no payout for it. */
    private function recentlyNotFound(MassPaymentRow $row): bool
    {
        return $row->last_check_outcome === PayoutOutcome::NotFound->value
            && $row->last_checked_at !== null
            && $row->last_checked_at->greaterThanOrEqualTo(now()->subMinutes(self::NOT_PAID_LOOKUP_MINUTES));
    }

    /** Close the batch once an admin's decision resolved its last pending line. */
    private function finalizeIfDone(int $batchId): void
    {
        $pending = MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batchId)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->exists();

        if (! $pending) {
            $this->processor->finalize($batchId);
        }
    }

    /**
     * The one provider request id of a line: the batch reference and the
     * line number. Stable for the line's whole life and unique across every
     * batch, so it is the only key a driver deduplicates on and the only key
     * a lookup searches by before the provider has named the payout.
     */
    public function providerRequestId(MassPaymentBatch $batch, MassPaymentRow $row): string
    {
        return (string) $batch->reference.'-'.(int) $row->line_no;
    }

    /**
     * Whether a batch with these totals waits for an admin, by the admin's
     * {@see MassPayoutApproval} setting. Above threshold compares what the
     * batch reserves, fees included, in the site currency, strictly above the
     * threshold.
     *
     * @param array<string, int|string> $summary
     */
    private function needsApproval(array $summary): bool
    {
        return match (MassPayoutApproval::fromSetting()) {
            MassPayoutApproval::Always         => true,
            MassPayoutApproval::Never          => false,
            MassPayoutApproval::AboveThreshold => DecimalMoney::compare(
                DecimalMoney::add((string) $summary['total_base_amount'], (string) $summary['total_base_fee'], DecimalMoney::CALCULATION_SCALE),
                MassPayoutApproval::threshold(),
            ) > 0,
        };
    }

    /**
     * The batch's method, still eligible for this wallet under the driver it
     * was priced for.
     *
     * @throws MassPayoutMethodUnavailableException when it is not
     */
    private function methodFor(User $user, Wallet $wallet, MassPaymentBatch $batch): WithdrawMethod
    {
        $method = $batch->withdraw_method_id === null
            ? null
            : $this->methods->findForWallet($user, $wallet, (int) $batch->withdraw_method_id);

        if ($method === null || (string) $method->mass_payout_driver !== (string) $batch->payout_driver) {
            throw new MassPayoutMethodUnavailableException(__('This payout method is no longer available for mass payouts. Discard this draft and build a new one.'));
        }

        return $method;
    }

    /**
     * Seam for tests: the batch confirm starts from, read in the owner's
     * scope with no lock. A race test returns a stale copy from here.
     *
     * @throws ModelNotFoundException for another user's batch or a wallet batch
     */
    protected function findBatchForConfirm(User $user, string $uuid): MassPaymentBatch
    {
        return $user->massPaymentBatches()->ofKind(MassPaymentKind::Payout)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Both per-user switches (withdraw and mass payout) and the module's own
     * access rule for the user's panel, checked again here because a service
     * that moves money does not rely on its route's middleware.
     *
     * @throws NotifyErrorException
     */
    private function assertFeatureAvailable(User $user): void
    {
        if (! $user->hasFeature('withdraw') || ! $user->hasFeature(self::FEATURE_KEY)) {
            throw new NotifyErrorException(__('You do not have permission to access this feature.'), 403);
        }

        if (! $this->features->isAccessible(self::FEATURE_KEY, null, $user)) {
            throw new NotifyErrorException(__('Mass payouts are not available for your account.'), 403);
        }
    }

    /**
     * Write the re-validated lines, the totals, the fingerprint and the terms
     * over a draft, under the batch lock and only while it is still a draft.
     * Returns null when the batch is no longer a draft.
     *
     * @param list<array<string, mixed>> $fresh
     */
    private function refreshDraft(User $user, MassPaymentBatch $batch, Wallet $wallet, WithdrawMethod $method, FeeMode $feeMode, array $fresh, string $fingerprint): ?MassPaymentBatch
    {
        return DB::transaction(function () use ($user, $batch, $wallet, $method, $feeMode, $fresh, $fingerprint): ?MassPaymentBatch {
            $draft = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Payout)
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->status() !== MassPaymentBatchStatus::Draft) {
                return null;
            }

            $stored  = MassPaymentRow::query()->where('mass_payment_batch_id', $draft->id)->get()->keyBy('line_no');
            $changed = 0;

            foreach ($fresh as $row) {
                $existing = $stored->get($row['line_no']);

                if (! $existing instanceof MassPaymentRow) {
                    continue;
                }

                $existing->forceFill(array_intersect_key($row, array_flip(self::REFRESHED_COLUMNS)));

                if ($existing->isDirty()) {
                    $existing->save();
                    $changed++;
                }
            }

            $previous = (string) $draft->preview_fingerprint;
            $metadata = is_array($draft->metadata) ? $draft->metadata : [];

            $draft->forceFill(array_merge(
                $this->totals($fresh, (string) $wallet->currency->code),
                [
                    'preview_fingerprint' => $fingerprint,
                    'metadata'            => array_merge($metadata, ['payout' => $this->terms($method, $feeMode)]),
                ],
            ))->save();

            $this->recordEvent($draft, MassPaymentBatchStatus::Draft->value, MassPaymentActor::user($user), 'refreshed', [
                'changed_rows'         => $changed,
                'previous_fingerprint' => $previous,
            ]);

            return $draft->refresh();
        }, 3);
    }

    /**
     * The GF4 gates: invalid lines block unless skipped, warnings block until
     * acknowledged, and the row and batch caps apply as they stand now.
     *
     * @throws MassPaymentNotConfirmableException
     */
    private function assertConfirmable(
        MassPaymentBatch $batch,
        MassPaymentConfirmation $confirmation,
        int $rowCount,
        int $validCount,
        int $invalidCount,
        int $warningCount,
        string $totalBaseAmount,
    ): void {
        $reason = match (true) {
            $validCount < 1                                               => MassPaymentNotConfirmableException::NO_VALID_ROWS,
            $invalidCount > 0 && ! $confirmation->skipInvalid             => MassPaymentNotConfirmableException::INVALID_ROWS,
            $warningCount > 0 && ! $confirmation->acknowledgeWarnings     => MassPaymentNotConfirmableException::WARNINGS_UNACKNOWLEDGED,
            $rowCount     > $this->walletBatches->maxRows()               => MassPaymentNotConfirmableException::ROW_CAP,
            $this->walletBatches->exceedsBatchAmountCap($totalBaseAmount) => MassPaymentNotConfirmableException::BATCH_AMOUNT_CAP,
            default                                                       => null,
        };

        if ($reason !== null) {
            throw new MassPaymentNotConfirmableException($reason, $batch);
        }
    }

    /**
     * The stored lines as the customer typed them, keyed by line number, in
     * the shape {@see self::evaluateLines()} reads, the account decrypted.
     *
     * @param  Collection<int, MassPaymentRow>                                                                                                          $rows
     * @return array<int, array{amount: string, note: string|null, reference: string|null, currency: string|null, account: array<string, string|null>}>
     */
    private function linesOf(Collection $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $account = is_array($row->payout_account) ? $row->payout_account : [];

            $lines[(int) $row->line_no] = [
                'amount'    => (string) $row->amount_input,
                'note'      => $row->note,
                'reference' => $row->client_reference,
                'currency'  => $row->currency_input,
                'account'   => array_map(fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $account),
            ];
        }

        return $lines;
    }

    /**
     * @param  Collection<int, MassPaymentRow> $rows
     * @return array<int, MassPaymentIssue>
     */
    private function keptIssuesOf(Collection $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $issue = $row->issue();

            if ($issue !== null && in_array($issue, self::KEPT_ISSUES, true)) {
                $kept[(int) $row->line_no] = $issue;
            }
        }

        return $kept;
    }

    /**
     * Validate and price every line against the source wallet and the
     * method. The result is one row-attribute array per line, ordered by line
     * number, with the warnings attached and the account details still in
     * plain form under `payout_account`; nothing is written.
     *
     * Issue order: a currency column naming another currency, then the
     * account details, then the note, then the price
     * ({@see MassPayoutPricer} has its own order). `$keptIssues`, keyed by line
     * number, holds an issue a line keeps whatever it now evaluates to.
     *
     * @param  array<int, array{amount: string, note: string|null, reference: string|null, currency: string|null, account: array<string, string|null>}> $lines      keyed by line number
     * @param  array<int, MassPaymentIssue>                                                                                                             $keptIssues
     * @return list<array<string, mixed>>
     */
    public function evaluateLines(User $owner, Wallet $wallet, WithdrawMethod $method, array $lines, FeeMode $feeMode, array $keptIssues = []): array
    {
        ksort($lines);

        $code   = (string) $wallet->currency->code;
        $driver = (string) $method->mass_payout_driver;
        $rows   = [];

        foreach ($lines as $lineNo => $line) {
            $evaluated = $this->accounts->evaluate($method, $line['account']);
            $account   = $evaluated['account'];
            $label     = $this->accounts->label($account);

            $row = [
                'line_no'             => (int) $lineNo,
                'recipient_input'     => mb_substr($label, 0, self::LABEL_MAX_LENGTH),
                'recipient_type'      => self::RECIPIENT_TYPE,
                'currency_input'      => $line['currency']  === null ? null : mb_substr($line['currency'], 0, self::CURRENCY_MAX_LENGTH),
                'client_reference'    => $line['reference'] === null ? null : mb_substr($line['reference'], 0, self::REFERENCE_MAX_LENGTH),
                'recipient_user_id'   => null,
                'recipient_wallet_id' => null,
                'recipient_label'     => mb_substr($label, 0, self::LABEL_MAX_LENGTH),
                'amount_input'        => mb_substr($line['amount'], 0, self::AMOUNT_MAX_LENGTH),
                'entered_amount'      => null,
                'base_amount'         => null,
                'base_fee'            => null,
                'net_amount'          => null,
                'payable_amount'      => null,
                'wallet_fee'          => null,
                'exchange_rate'       => null,
                'note'                => $line['note'] === null ? null : mb_substr($line['note'], 0, MassPaymentCsvParser::NOTE_MAX_LENGTH),
                'status'              => MassPaymentRowStatus::Invalid->value,
                'issue_code'          => null,
                'warnings'            => null,
                'metadata'            => null,
                'payout_driver'       => $driver,
                'payout_account'      => $account === [] ? null : $account,
                'payout_account_hash' => $account === [] ? null : $this->accounts->hash($account),
            ];

            $issue = $keptIssues[$lineNo] ?? match (true) {
                $line['currency'] !== null && strcasecmp($line['currency'], $code) !== 0 => MassPaymentIssue::WrongCurrency,
                default                                                                  => $evaluated['issue'],
            };

            if ($issue === null && $line['note'] !== null && mb_strlen($line['note']) > MassPaymentCsvParser::NOTE_MAX_LENGTH) {
                $issue = MassPaymentIssue::NoteTooLong;
            }

            $quote = null;

            if ($issue === null) {
                $quote = $this->pricer->price($owner, $method, $line['amount'], $feeMode);
                $issue = $quote->issue;
            }

            if ($issue !== null || $quote === null || ! $quote->isPayable()) {
                $rows[] = array_merge($row, ['issue_code' => ($issue ?? MassPaymentIssue::InvalidAmount)->value]);

                continue;
            }

            $rows[] = array_merge($row, $quote->figures, [
                'status' => MassPaymentRowStatus::Valid->value,
            ]);
        }

        return $this->withWarnings($rows);
    }

    /**
     * The preview fingerprint: a sha256 over the wallet, the currency, the
     * method, the fee mode and, line by line, everything that decides which
     * account is paid what. It catches two accounts swapped at the same
     * total, and a fee mode changed by the admin between preview and confirm.
     *
     * Figures are canonicalised to eight places, so the fingerprint built
     * from freshly priced lines equals the one rebuilt from the stored rows.
     *
     * @param iterable<array<string, mixed>|MassPaymentRow> $rows
     */
    public function fingerprint(int $walletId, int $currencyId, int $methodId, FeeMode $feeMode, iterable $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $value = fn (string $key): mixed => $row instanceof MassPaymentRow ? $row->getAttribute($key) : ($row[$key] ?? null);

            $line = [
                (int) $value('line_no'),
                (string) $value('status'),
                $value('payout_account_hash') === null ? null : (string) $value('payout_account_hash'),
            ];

            foreach (self::FIGURES as $figure) {
                $amount = $value($figure);
                $line[] = $amount === null ? null : DecimalMoney::normalize($amount, DecimalMoney::CALCULATION_SCALE);
            }

            $rate   = $value('exchange_rate');
            $line[] = $rate          === null ? null : DecimalMoney::rate((string) $rate);
            $line[] = $value('note') === null ? null : (string) $value('note');

            $lines[(int) $value('line_no')] = $line;
        }

        ksort($lines);

        return hash('sha256', json_encode(
            [MassPaymentKind::Payout->value, $walletId, $currencyId, $methodId, $feeMode->value, array_values($lines)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * The terms a payout batch is built on, frozen at draft so the batch
     * never depends on a withdraw method edited or deleted later.
     *
     * @return array{method_id: int, method_name: string, driver: string, currency: string, conversion_rate: string, fee_mode: string, fields: list<array{name: string, label: string, required: bool}>}
     */
    public function terms(WithdrawMethod $method, FeeMode $feeMode): array
    {
        return [
            'method_id'       => (int) $method->id,
            'method_name'     => (string) $method->name,
            'driver'          => (string) $method->mass_payout_driver,
            'currency'        => strtoupper(trim((string) $method->currency)),
            'conversion_rate' => DecimalMoney::rate((string) $method->conversion_rate),
            'fee_mode'        => $feeMode->value,
            'fields'          => array_map(fn (array $field): array => [
                'name'     => (string) $field['name'],
                'label'    => is_scalar($field['label'] ?? null) && trim((string) $field['label']) !== '' ? (string) $field['label'] : (string) $field['name'],
                'required' => $field['validation'] === 'required',
            ], $this->accounts->fields($method)),
        ];
    }

    /**
     * One evaluated line as the attributes `insert()` writes. The row is
     * filled through the model so the casts run - the account is encrypted,
     * the warnings and metadata JSON-encoded - since `insert()` itself skips
     * every cast.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function insertable(MassPaymentBatch $batch, array $row, mixed $now): array
    {
        $model = (new MassPaymentRow)->forceFill(array_merge($row, [
            'mass_payment_batch_id' => $batch->id,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]));

        return $model->getAttributes();
    }

    /**
     * Attach the duplicate warnings to the valid lines: the same account, the
     * same account paid the same amount with the same note, and the same
     * client reference. Each warning names a line it repeats: a later line
     * points at the first, the first at the next one.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withWarnings(array $rows): array
    {
        $groups = [
            MassPaymentWarning::DuplicateAccount->value        => [],
            MassPaymentWarning::DuplicateAccountPayment->value => [],
            MassPaymentWarning::DuplicateReference->value      => [],
        ];

        foreach ($rows as $index => $row) {
            if ($row['status'] !== MassPaymentRowStatus::Valid->value) {
                continue;
            }

            $groups[MassPaymentWarning::DuplicateAccount->value][(string) $row['payout_account_hash']][] = $index;
            $groups[MassPaymentWarning::DuplicateAccountPayment->value][json_encode([
                $row['payout_account_hash'],
                $row['entered_amount'],
                $row['note'],
            ], JSON_THROW_ON_ERROR)][] = $index;

            if ($row['client_reference'] !== null) {
                $groups[MassPaymentWarning::DuplicateReference->value][$row['client_reference']][] = $index;
            }
        }

        foreach ($groups as $code => $byKey) {
            foreach ($byKey as $indexes) {
                if (count($indexes) < 2) {
                    continue;
                }

                foreach ($indexes as $position => $index) {
                    $other = $position === 0 ? $indexes[1] : $indexes[0];

                    $rows[$index]['warnings'][] = [
                        'code'              => $code,
                        'duplicate_of_line' => $rows[$other]['line_no'],
                    ];
                }
            }
        }

        return $rows;
    }

    protected function batchKind(): MassPaymentKind
    {
        return MassPaymentKind::Payout;
    }
}
