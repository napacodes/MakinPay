<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Constants\CurrencyRole;
use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentIssue;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Enums\MassPayment\MassPaymentWarning;
use App\Enums\UserStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MassPaymentChangedException;
use App\Exceptions\MassPaymentNotConfirmableException;
use App\Exceptions\NotifyErrorException;
use App\Jobs\ProcessMassPaymentBatch;
use App\Models\Admin;
use App\Models\MassPaymentBatch;
use App\Models\MassPaymentEvent;
use App\Models\MassPaymentRow;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FeatureManager;
use App\Services\MassPayment\Concerns\SharesBatchSteps;
use App\Services\Money\SendMoneyService;
use App\Services\Money\WalletPinGuard;
use App\Services\WalletService;
use App\Support\AmountEntry;
use App\Support\DecimalMoney;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Mass payments: one merchant paying many wallets from one source wallet.
 *
 * This part builds, discards and confirms drafts. A draft is the file or the
 * manual grid read, every line's recipient resolved strictly, its amount
 * validated and priced exactly as one send money transfer would be, and its
 * figures frozen for the preview; building one moves no money. Confirm
 * reserves the total as one PENDING debit per valid line and queues the
 * payout, which lives in {@see MassPaymentProcessor}.
 *
 * Amounts are rejected, never truncated: a figure finer than the currency
 * holds, a thousands separator, an exponent, a sign or zero is an invalid
 * line, not a figure quietly rounded into something the merchant did not type.
 *
 * Every per-line figure comes from {@see SendMoneyService::price()}, so a line
 * costs exactly what the same transfer sent alone would cost: the fee is per
 * line, and a fixed fee and the min/max caps apply to each. The floats it
 * returns are normalised at once to decimal strings - wallet figures at the
 * wallet currency's decimals, base figures at the base currency's - and every
 * total is DecimalMoney arithmetic over those strings.
 *
 * Lock order (for the whole mass payment module): batch, then row, then the
 * debit transaction, then exactly one wallet. createDraft takes no lock at
 * all; discard locks the batch only; confirm locks the batch, then the source
 * wallet, and writes a draft's rows only while it holds the batch lock.
 * requestStop and resume lock the batch only; closeAfterReview locks the
 * batch, then each flagged line, and moves no money. None of them holds a
 * transaction across more than one line: a stop or a resume commits first and
 * the processor then works the lines one at a time, each in its own.
 */
class MassPaymentService
{
    use SharesBatchSteps;

    /**
     * The most lines one batch may ever have, whatever the setting says:
     * confirm inserts one debit per line while it holds the wallet lock.
     */
    public const HARD_MAX_ROWS = 1000;

    public const DEFAULT_MAX_ROWS = 500;

    public const DRAFT_TTL_HOURS = 24;

    public const SOURCE_CSV = 'csv';

    public const SOURCE_MANUAL = 'manual';

    /** Rows a schedule kept and rang up again: manual-shaped, labelled for what they are. */
    public const SOURCE_SCHEDULE = 'schedule';

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_API = 'api';

    /** The scheduler, under the mandate the customer gave when they set the schedule up. */
    public const CHANNEL_SCHEDULE = 'schedule';

    /**
     * How many recipient lines one account may have resolved into drafts in
     * an hour, web and API together. A draft needs no balance and no PIN and
     * answers every line with a masked name or a refusal, so a budget on
     * requests alone would let a client check thousands of emails or numbers
     * a minute, a list send money's one-at-a-time preview is throttled
     * precisely to prevent. Two full drafts at the hard row cap still fit.
     */
    public const RECIPIENT_LOOKUPS_PER_HOUR = 2 * self::HARD_MAX_ROWS;

    private const RECIPIENT_LOOKUP_WINDOW_SECONDS = 3600;

    /** The only amount shape accepted: digits, optionally a point and more digits. */
    private const AMOUNT_PATTERN = '/^\d+(\.\d+)?$/';

    /** decimal(24,8) holds sixteen integer digits; typed amounts get fifteen. */
    private const MAX_TYPED_INTEGER_DIGITS = 15;

    private const MAX_STORED_INTEGER_DIGITS = 16;

    private const RECIPIENT_MAX_LENGTH = 191;

    private const AMOUNT_MAX_LENGTH = 64;

    private const REFERENCE_MAX_LENGTH = 100;

    private const CURRENCY_MAX_LENGTH = 10;

    private const FILENAME_MAX_LENGTH = 191;

    /**
     * An inline stop refunds up to this many lines in the request that asked
     * for it, or for STOP_RUN_SECONDS, then leaves the rest to the job or the
     * resume command. One batch never has more lines than this.
     */
    public const STOP_ROW_BUDGET = self::HARD_MAX_ROWS;

    public const STOP_RUN_SECONDS = 20;

    /** Discarded and expired drafts are deleted this many days after they were created. */
    public const PRUNE_AFTER_DAYS = 30;

    private const ADMIN_NOTE_MAX_LENGTH = 2000;

    private const EVENT_TEXT_MAX_LENGTH = 500;

    private const INSERT_CHUNK = 250;

    /** @var list<string> */
    private const FIGURES = ['entered_amount', 'base_amount', 'base_fee', 'net_amount', 'payable_amount'];

    /**
     * The row columns a confirm-time refresh rewrites: everything the
     * re-validation can change. What the customer typed never changes.
     *
     * @var list<string>
     */
    private const REFRESHED_COLUMNS = [
        'recipient_type',
        'recipient_user_id',
        'recipient_wallet_id',
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
    ];

    /**
     * Issues that depend only on what was typed, or on a cut the draft made
     * to it, and so are kept as they are at confirm. A note over the limit was
     * stored cut to the limit; reading it back must not make the line valid.
     *
     * @var list<MassPaymentIssue>
     */
    private const KEPT_ISSUES = [MassPaymentIssue::NoteTooLong, MassPaymentIssue::RecipientChanged];

    public function __construct(
        private readonly WalletService $wallets,
        private readonly SendMoneyService $sendMoney,
        private readonly MassPaymentRecipientResolver $resolver,
        private readonly MassPaymentCsvParser $parser,
        private readonly WalletPinGuard $pins,
        private readonly FeatureManager $features,
        private readonly MassPaymentLedger $ledger,
        private readonly MassPaymentProcessor $processor,
    ) {}

    /**
     * The row cap: the admin's `mass_payment_max_rows`, 500 when the setting
     * row is missing or unusable, never above {@see self::HARD_MAX_ROWS}.
     */
    public function maxRows(): int
    {
        $configured = setting('mass_payment_max_rows', self::DEFAULT_MAX_ROWS);
        $rows       = is_numeric($configured) ? (int) $configured : self::DEFAULT_MAX_ROWS;

        if ($rows < 1) {
            $rows = self::DEFAULT_MAX_ROWS;
        }

        return min($rows, self::HARD_MAX_ROWS);
    }

    /**
     * The batch cap in base currency, or null for none. `'0'`, the default
     * when the setting row is missing, means no cap.
     */
    public function maxBatchAmount(): ?string
    {
        $configured = setting('mass_payment_max_batch_amount', '0');

        if (! is_numeric($configured) || DecimalMoney::compare($configured, '0') <= 0) {
            return null;
        }

        return DecimalMoney::normalize($configured);
    }

    /**
     * Whether a batch whose lines add up to this base amount (fees excluded,
     * as the per-line role limits exclude them) is over the batch cap.
     */
    public function exceedsBatchAmountCap(string $totalBaseAmount): bool
    {
        $cap = $this->maxBatchAmount();

        return $cap !== null && DecimalMoney::compare($totalBaseAmount, $cap) > 0;
    }

    /**
     * Build a draft from an uploaded file (`csv`) or the manual grid
     * (`manual`) against one of the user's wallets. No money moves and no PIN
     * is asked; the draft expires after {@see self::DRAFT_TTL_HOURS} hours.
     *
     * @param array<int|string, mixed>|UploadedFile $input
     *
     * @throws ValidationException    for a file or grid that cannot be read, or one over the row cap
     * @throws NotifyErrorException   when the wallet cannot send, or (429) the lines are over the hourly recipient budget
     * @throws ModelNotFoundException when the wallet is not the user's
     */
    public function createDraft(User $user, string $walletUuid, array|UploadedFile $input, string $source, string $channel, ?string $name = null, ?int $scheduledPaymentId = null): MassPaymentBatch
    {
        if (! in_array($channel, [self::CHANNEL_WEB, self::CHANNEL_API, self::CHANNEL_SCHEDULE], true)) {
            throw new InvalidArgumentException("Unknown mass payment channel [{$channel}].");
        }

        $isFile = match ($source) {
            self::SOURCE_CSV      => true,
            self::SOURCE_MANUAL   => false,
            self::SOURCE_SCHEDULE => false,
            default               => throw new InvalidArgumentException("Unknown mass payment source [{$source}]."),
        };

        if ($isFile !== $input instanceof UploadedFile) {
            throw new InvalidArgumentException('A csv draft takes an uploaded file and a manual draft takes rows.');
        }

        $wallet = $user->wallets()->where('uuid', $walletUuid)->with('currency.activeRoles')->firstOrFail();
        $this->wallets->assertOperational($wallet, CurrencyRole::SENDER);

        $lines = $input instanceof UploadedFile
            ? $this->parser->parse($input, $this->maxRows())
            : $this->parser->fromRows($input, $this->maxRows());

        $this->spendRecipientLookups($user, count($lines));

        $rows        = $this->evaluateLines($user, $wallet, $lines);
        $totals      = $this->totals($rows, (string) $wallet->currency->code);
        $fingerprint = $this->fingerprint((int) $wallet->id, (int) $wallet->currency_id, $rows);

        return DB::transaction(function () use ($user, $wallet, $input, $source, $channel, $rows, $totals, $fingerprint, $name, $scheduledPaymentId): MassPaymentBatch {
            $batch = MassPaymentBatch::query()->create(array_merge([
                'uuid'                 => (string) Str::uuid(),
                'reference'            => $this->newReference(),
                'name'                 => self::batchName($name),
                'user_id'              => $user->id,
                'wallet_id'            => $wallet->id,
                'currency_id'          => $wallet->currency_id,
                'currency_code'        => (string) $wallet->currency->code,
                'kind'                 => 'wallet',
                'source'               => $source,
                'channel'              => $channel,
                'scheduled_payment_id' => $scheduledPaymentId,
                'original_filename'    => $input instanceof UploadedFile ? $this->safeFilename($input) : null,
                'status'               => MassPaymentBatchStatus::Draft->value,
                'preview_fingerprint'  => $fingerprint,
                'expires_at'           => now()->addHours(self::DRAFT_TTL_HOURS),
            ], $totals));

            $now = now();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                MassPaymentRow::query()->insert(array_map(fn (array $row): array => array_merge($row, [
                    'mass_payment_batch_id' => $batch->id,
                    'warnings'              => $row['warnings'] === null ? null : json_encode($row['warnings'], JSON_THROW_ON_ERROR),
                    'metadata'              => $row['metadata'] === null ? null : json_encode($row['metadata'], JSON_THROW_ON_ERROR),
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]), $chunk));
            }

            $this->recordEvent($batch, null, MassPaymentActor::user($user), 'created', [
                'row_count'     => $batch->row_count,
                'invalid_count' => $batch->invalid_count,
            ]);

            return $batch->refresh();
        });
    }

    /**
     * The customer's name for a batch, cleaned the way a note is, or null
     * when there is none. The request caps the length; this only refuses to
     * store markup or control characters in a label shown on two surfaces.
     */
    public static function batchName(?string $name): ?string
    {
        $clean = MassPaymentCsvParser::cleanNote($name);

        return $clean === null ? null : mb_substr($clean, 0, 100);
    }

    /**
     * One recipient checked while the customer types it, before any draft
     * exists: the app's "Add a payment" sheet shows who it found.
     *
     * Exactly the draft's lookup - the same resolver, rule and wallet - so
     * what the sheet shows is what the draft will say. It costs one line of
     * the same hourly budget a draft spends, so typing cannot look up more
     * people than uploading a file could, and it answers only with the
     * resolution's client-safe label (a first name, a last initial and a
     * masked identifier), never an id or a full name.
     *
     * @throws NotifyErrorException   when the hourly budget is spent (429)
     * @throws ModelNotFoundException for a wallet that is not the customer's
     */
    public function previewRecipient(User $user, string $walletUuid, string $recipient): RecipientResolution
    {
        $wallet = $user->wallets()->where('uuid', $walletUuid)->with('currency.activeRoles')->firstOrFail();
        $this->wallets->assertOperational($wallet, CurrencyRole::SENDER);

        $this->spendRecipientLookups($user, 1);

        return $this->resolver->resolveMany($user, $wallet, [trim($recipient)])[0];
    }

    /** The rate limiter key that counts this account's resolved recipient lines. */
    public function recipientLookupKey(User $user): string
    {
        return 'mass-payment-recipients:'.$user->id;
    }

    /**
     * Charge a draft's lines to the account's hourly recipient budget, or
     * refuse the whole draft, before any line is resolved. A refused draft
     * spends nothing.
     *
     * @throws NotifyErrorException with status 429 when the lines do not fit
     */
    private function spendRecipientLookups(User $user, int $lines): void
    {
        $key = $this->recipientLookupKey($user);

        if (RateLimiter::attempts($key) + $lines > self::RECIPIENT_LOOKUPS_PER_HOUR) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw new NotifyErrorException(__('You have checked too many recipients in the last hour. Try again in :minutes minutes.', [
                'minutes' => $minutes,
            ]), 429);
        }

        RateLimiter::increment($key, self::RECIPIENT_LOOKUP_WINDOW_SECONDS, $lines);
    }

    /**
     * Discard one of the user's drafts. Discarding an already discarded batch
     * returns it unchanged; anything past Draft is refused.
     *
     * @throws NotifyErrorException   when the batch is no longer a draft
     * @throws ModelNotFoundException for another user's batch or a payout batch
     */
    public function discard(User $user, string $uuid): MassPaymentBatch
    {
        $batch = $user->massPaymentBatches()->ofKind(MassPaymentKind::Wallet)->where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($user, $batch): MassPaymentBatch {
            $locked = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Wallet)
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
     * Confirm one of the user's drafts: reserve its total from the source
     * wallet, written as one PENDING debit per valid line, and queue it.
     *
     * Phase 0. The wallet PIN is checked before the batch is even looked up,
     * so a wrong PIN can probe nothing, then both per-user features and the
     * module's own access rule are checked again.
     *
     * Phase 1, with no transaction open. The batch is found in the owner's
     * scope (another user's is a 404). A batch already past Draft is a replay
     * and debits nothing. Every line is re-validated afresh against the
     * wallet it was pinned to; if the result differs from the stored preview,
     * the draft is refreshed under its lock - only while it is still a draft -
     * and the customer is sent back to review it. The fingerprint and total
     * the client reviewed must match the stored preview, and the gates
     * (invalid lines, unacknowledged warnings, the row and batch caps) apply.
     *
     * Phase 2, one transaction: lock the batch and re-check Draft, recompute
     * the fingerprint and the total from the stored rows, lock the wallet,
     * debit it with the balance check in the same statement, and only then
     * insert one debit per valid line, in line order. A short balance throws
     * before anything is written, so the batch stays a draft and the balance
     * is untouched. The batch row lock plus the Draft check is what makes a
     * second or concurrent confirm a replay instead of a second debit.
     *
     * Phase 3, outside the transaction: a reservation this call made queues
     * {@see ProcessMassPaymentBatch} after commit, so the payout can never
     * start on a reservation that rolled back. A replay queues nothing.
     *
     * @throws NotifyErrorException               wrong or missing PIN, a feature switched off, or a wallet that cannot send
     * @throws InsufficientBalanceException       the wallet holds less than the total
     * @throws MassPaymentChangedException        the batch is not the one the customer reviewed
     * @throws MassPaymentNotConfirmableException a gate refused it
     * @throws ModelNotFoundException             another user's batch
     */
    public function confirm(User $user, string $uuid, MassPaymentConfirmation $confirmation, bool $underMandate = false): ConfirmedMassPayment
    {
        // The scheduler has no PIN to give: the customer gave it when they set
        // the schedule up. Everyone else types it now.
        if (! $underMandate) {
            $this->pins->assert($user, $confirmation->pin);
        }

        $this->assertFeatureAvailable($user);

        $batch = $this->findBatchForConfirm($user, $uuid);

        if ($batch->status() !== MassPaymentBatchStatus::Draft) {
            return $this->replayOrRefuse($batch);
        }

        if ($batch->expires_at !== null && $batch->expires_at->isPast()) {
            return $this->expire($user, $batch);
        }

        $wallet = $user->wallets()->whereKey($batch->wallet_id)->with('currency.activeRoles')->firstOrFail();
        $this->wallets->assertOperational($wallet, CurrencyRole::SENDER);

        $stored           = $batch->rows()->get();
        $fresh            = $this->evaluateLines($user, $wallet, $this->linesOf($stored), $this->pinnedWalletsOf($stored), $this->keptIssuesOf($stored));
        $freshFingerprint = $this->fingerprint((int) $wallet->id, (int) $wallet->currency_id, $fresh);

        if (! hash_equals((string) $batch->preview_fingerprint, $freshFingerprint)) {
            $refreshed = $this->refreshDraft($user, $batch, $wallet, $fresh, $freshFingerprint);

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

        $confirmed = DB::transaction(function () use ($user, $batch, $confirmation, $actor, &$replayed): MassPaymentBatch {
            $replayed = false;

            $draft = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Wallet)
                ->lockForUpdate()
                ->firstOrFail();

            if ($draft->status() !== MassPaymentBatchStatus::Draft) {
                $this->refuseUnlessConfirmed($draft);
                $replayed = true;

                return $draft;
            }

            $rows = MassPaymentRow::query()
                ->where('mass_payment_batch_id', $draft->id)
                ->with('recipientWallet:id,uuid')
                ->orderBy('line_no')
                ->get();

            $summary = $this->totals($this->figuresOf($rows), (string) $draft->currency_code);
            $total   = (string) $summary['total_payable'];

            if (! $this->matchesPreview($confirmation, $this->fingerprint((int) $draft->wallet_id, (int) $draft->currency_id, $rows), $total)
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

            $this->wallets->assertOperational($locked, CurrencyRole::SENDER);

            if ($this->wallets->tryDebit($locked, $total) !== 1) {
                throw new InsufficientBalanceException(__('Insufficient wallet balance.'));
            }

            $debited = 0;
            $rate    = null;

            foreach ($rows as $row) {
                if ($row->status() !== MassPaymentRowStatus::Valid) {
                    continue;
                }

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

            $draft->forceFill([
                'status'                => MassPaymentBatchStatus::Queued->value,
                'confirmed_at'          => $now,
                'exchange_rate'         => $rate ?? $this->wallets->fxRate($locked),
                'skip_invalid'          => $confirmation->skipInvalid,
                'warnings_acknowledged' => $confirmation->acknowledgeWarnings,
                'last_progress_at'      => $now,
            ])->save();

            $this->recordEvent($draft, MassPaymentBatchStatus::Draft->value, $actor, 'confirmed', [
                'debited_rows'  => $debited,
                'skipped_rows'  => $skipped,
                'total_payable' => $total,
                'channel'       => $confirmation->channel,
            ]);

            return $draft;
        }, 3);

        if (! $replayed) {
            ProcessMassPaymentBatch::dispatch((int) $confirmed->id)->afterCommit();
        }

        return new ConfirmedMassPayment($confirmed->refresh(), $replayed);
    }

    /**
     * Confirm with no PIN, for the scheduler: the customer authorised the
     * batch with their PIN when they set the schedule up, and nobody is at
     * the keyboard when it runs. Everything else - the feature, the preview
     * match, the caps, the one debit under the wallet lock - is exactly
     * confirm()'s.
     */
    public function confirmUnderMandate(User $user, string $uuid, MassPaymentConfirmation $confirmation): ConfirmedMassPayment
    {
        return $this->confirm($user, $uuid, $confirmation, underMandate: true);
    }

    /**
     * Stop a confirmed batch: every line not yet paid is refunded, its whole
     * payable, fee included, and the batch is finalized.
     *
     * The owner may stop their own Queued or Processing batch; the lines are
     * refunded as `stopped`. An admin may also cancel the remaining lines of
     * a batch held for review, with a reason; they are refunded as
     * `cancelled_by_admin`.
     *
     * Only the batch row is locked, only to record the request, and nothing
     * else is written under that lock. The processor then works the lines
     * inline, under the per-batch process lock, each line in its own
     * transaction. A line a runner has already locked to pay finishes paid;
     * the stop takes effect from the next line. When another runner holds the
     * process lock, that runner reads the stop before each line and does the
     * refunds instead; anything the inline run leaves is picked up by the job
     * or the resume command.
     *
     * A batch that is already finished, or already being stopped, is
     * `already_resolved` and is not stopped twice.
     *
     * @throws NotifyErrorException   the batch cannot be stopped in its state, or an admin gave no reason
     * @throws ModelNotFoundException the owner asked about another user's batch, or a payout batch
     */
    public function requestStop(MassPaymentBatch $batch, MassPaymentActor $actor, ?string $reason = null): MassPaymentActionResult
    {
        $reason = trim((string) $reason);

        $stoppable = match ($actor->type) {
            MassPaymentActor::TYPE_USER  => [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing],
            MassPaymentActor::TYPE_ADMIN => [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing, MassPaymentBatchStatus::NeedsReview],
            default                      => throw new InvalidArgumentException('Only the owner or an admin can stop a mass payment.'),
        };

        if ($actor->type === MassPaymentActor::TYPE_ADMIN && $reason === '') {
            throw new NotifyErrorException(__('Give a reason for cancelling the remaining rows.'), 422);
        }

        $result = DB::transaction(function () use ($batch, $actor, $stoppable, $reason): MassPaymentActionResult {
            $locked = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->when($actor->type === MassPaymentActor::TYPE_USER, fn ($query) => $query
                    ->where('user_id', $actor->id)
                    ->ofKind(MassPaymentKind::Wallet))
                ->lockForUpdate()
                ->firstOrFail();

            $status = $locked->status();

            if ($status->isTerminal() || $locked->stop_requested_at !== null) {
                return MassPaymentActionResult::alreadyResolved($locked);
            }

            if (! in_array($status, $stoppable, true)) {
                throw new NotifyErrorException(__('This mass payment cannot be stopped now.'), 409);
            }

            $locked->forceFill([
                'stop_requested_at'      => now(),
                'stop_requested_by_type' => $actor->type,
                'stop_requested_by_id'   => $actor->id,
            ])->save();

            $this->recordEvent($locked, $status->value, $actor, 'stop_requested', $reason === '' ? [] : [
                'reason' => mb_substr($reason, 0, self::EVENT_TEXT_MAX_LENGTH),
            ]);

            return MassPaymentActionResult::done($locked);
        }, 3);

        if ($result->batch->stop_requested_at !== null && ! $result->batch->status()->isTerminal()) {
            $this->processor->run((int) $result->batch->id, self::STOP_ROW_BUDGET, now()->addSeconds(self::STOP_RUN_SECONDS));
        }

        return new MassPaymentActionResult($result->outcome, $result->batch->refresh());
    }

    /**
     * Let a batch held for review carry on paying: back to Processing, and
     * the payout job queued once that commits.
     *
     * Refused while anything that held it still stands: a line flagged for a
     * ledger anomaly or a batch whose ledger did not add up (those are closed
     * after review instead), a stop already under way, or a sender who still
     * cannot pay. Locks the batch only.
     *
     * @throws NotifyErrorException the batch cannot be resumed
     */
    public function resume(MassPaymentBatch $batch, Admin $admin): MassPaymentActionResult
    {
        $result = DB::transaction(function () use ($batch, $admin): MassPaymentActionResult {
            $locked = MassPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $status = $locked->status();

            if ($status->isTerminal() || in_array($status, [MassPaymentBatchStatus::Queued, MassPaymentBatchStatus::Processing], true)) {
                return MassPaymentActionResult::alreadyResolved($locked);
            }

            if ($status !== MassPaymentBatchStatus::NeedsReview) {
                throw new NotifyErrorException(__('Only a mass payment held for review can be resumed.'), 409);
            }

            if ($locked->stop_requested_at !== null) {
                throw new NotifyErrorException(__('The remaining rows of this mass payment are already being returned.'), 409);
            }

            if ($this->hasLedgerAnomaly($locked) || $this->flaggedRows($locked)->exists()) {
                throw new NotifyErrorException(__('This mass payment has a ledger mismatch. Reconcile it, then close it after review.'), 409);
            }

            if (! $this->canTransact(User::query()->find($locked->user_id))) {
                throw new NotifyErrorException(__('The sender account is still not able to make payments.'), 409);
            }

            $holdReason = $locked->hold_reason;

            $locked->forceFill([
                'status'               => MassPaymentBatchStatus::Processing->value,
                'hold_reason'          => null,
                'reviewed_by_admin_id' => $admin->getKey(),
                'last_progress_at'     => now(),
            ])->save();

            $this->recordEvent($locked, MassPaymentBatchStatus::NeedsReview->value, MassPaymentActor::admin($admin), 'resumed', [
                'hold_reason' => $holdReason,
            ]);

            return MassPaymentActionResult::done($locked);
        }, 3);

        if ($result->is(MassPaymentActionResult::DONE)) {
            ProcessMassPaymentBatch::dispatch((int) $result->batch->id)->afterCommit();
        }

        return new MassPaymentActionResult($result->outcome, $result->batch->refresh());
    }

    /**
     * Close a batch held for a ledger anomaly once an admin has reconciled it
     * by hand with the existing balance tools. Moves no money.
     *
     * Locks the batch, then each flagged line, and marks those lines
     * `reviewed` (`closed_after_review`); their reserved debits are left as
     * they are. The batch is then finalized and closes as ClosedAfterReview.
     * A batch whose own ledger did not add up closes the same way, from its
     * lines' figures, since finalize would otherwise refuse it again. A batch
     * that still has unflagged lines to pay stays held, with nothing flagged,
     * for an admin to resume or cancel; the result is then `not_ready`.
     *
     * @throws NotifyErrorException no note, or nothing in the batch to close after review
     */
    public function closeAfterReview(MassPaymentBatch $batch, Admin $admin, string $note): MassPaymentActionResult
    {
        $note = trim($note);

        if ($note === '') {
            throw new NotifyErrorException(__('Add a note saying how the batch was reconciled.'), 422);
        }

        $actor = MassPaymentActor::admin($admin);

        $result = DB::transaction(function () use ($batch, $admin, $actor, $note): MassPaymentActionResult {
            $locked = MassPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $status = $locked->status();

            if ($status->isTerminal()) {
                return MassPaymentActionResult::alreadyResolved($locked);
            }

            if ($status !== MassPaymentBatchStatus::NeedsReview) {
                throw new NotifyErrorException(__('Only a mass payment held for review can be closed after review.'), 409);
            }

            $flagged = $this->flaggedRows($locked)->orderBy('id')->lockForUpdate()->get();

            if ($flagged->isEmpty() && ! $this->hasLedgerAnomaly($locked)) {
                throw new NotifyErrorException(__('Nothing in this mass payment is flagged for review.'), 409);
            }

            foreach ($flagged as $row) {
                $row->forceFill([
                    'status'     => MassPaymentRowStatus::Reviewed->value,
                    'issue_code' => MassPaymentIssue::ClosedAfterReview->value,
                ])->save();

                $this->recordEvent($locked, MassPaymentRowStatus::Pending->value, $actor, 'row_reviewed', [
                    'line_no' => (int) $row->line_no,
                ], (int) $row->id, MassPaymentRowStatus::Reviewed->value);
            }

            $locked->forceFill([
                'reviewed_by_admin_id' => $admin->getKey(),
                'admin_note'           => mb_substr($note, 0, self::ADMIN_NOTE_MAX_LENGTH),
            ])->save();

            $this->recordEvent($locked, $status->value, $actor, 'closed_after_review', [
                'reviewed_rows' => $flagged->count(),
                'note'          => mb_substr($note, 0, self::EVENT_TEXT_MAX_LENGTH),
            ]);

            return MassPaymentActionResult::done($locked);
        }, 3);

        if (! $result->is(MassPaymentActionResult::DONE)) {
            return $result;
        }

        $finalized = $this->processor->finalize((int) $result->batch->id, afterReview: true);

        return new MassPaymentActionResult($finalized->outcome, $result->batch->refresh());
    }

    /**
     * Expire one draft that is past its expiry, under its lock. Returns
     * whether this call expired it; anything else is left alone.
     */
    public function expireDraft(int $batchId): bool
    {
        return DB::transaction(function () use ($batchId): bool {
            $draft = MassPaymentBatch::query()->whereKey($batchId)->lockForUpdate()->first();

            if ($draft === null
                || $draft->status() !== MassPaymentBatchStatus::Draft
                || $draft->expires_at === null
                || $draft->expires_at->isFuture()) {
                return false;
            }

            $draft->forceFill(['status' => MassPaymentBatchStatus::Expired->value])->save();

            $this->recordEvent($draft, MassPaymentBatchStatus::Draft->value, MassPaymentActor::system(), 'expired');

            return true;
        }, 3);
    }

    /**
     * Delete a discarded or expired draft with its lines and its audit trail,
     * which is what removes the recipients' personal data. A draft never held
     * money; one that was ever confirmed, or has any line bound to a ledger
     * row, is never deleted. Returns whether this call deleted it.
     */
    public function purgeDraft(int $batchId): bool
    {
        return DB::transaction(function () use ($batchId): bool {
            $batch = MassPaymentBatch::query()->whereKey($batchId)->lockForUpdate()->first();

            if ($batch === null
                || ! in_array($batch->status(), [MassPaymentBatchStatus::Discarded, MassPaymentBatchStatus::Expired], true)
                || $batch->confirmed_at !== null) {
                return false;
            }

            $bound = MassPaymentRow::query()
                ->where('mass_payment_batch_id', $batch->id)
                ->where(fn ($query) => $query
                    ->whereNotNull('debit_transaction_id')
                    ->orWhereNotNull('credit_transaction_id')
                    ->orWhereNotNull('refund_transaction_id'))
                ->exists();

            if ($bound) {
                return false;
            }

            MassPaymentEvent::query()->where('mass_payment_batch_id', $batch->id)->delete();
            MassPaymentRow::query()->where('mass_payment_batch_id', $batch->id)->delete();
            MassPaymentBatch::query()->whereKey($batch->id)->delete();

            return true;
        }, 3);
    }

    /**
     * Validate, resolve and price every line against the source wallet. The
     * result is one row-attribute array per line, ordered by line number,
     * with the warnings attached; nothing is written.
     *
     * Confirm re-validates a draft through here with two extra maps, both
     * keyed by line number. `$pinnedWallets` holds the wallet a line was
     * pinned to at preview: a line that now resolves to any other wallet is
     * `recipient_changed`, never silently re-pointed. `$keptIssues` holds an
     * issue a line keeps whatever it now resolves to.
     *
     * @param  array<int, array{recipient: string, amount: string, note: string|null, reference: string|null, currency: string|null}> $lines         keyed by line number
     * @param  array<int, int>                                                                                                        $pinnedWallets
     * @param  array<int, MassPaymentIssue>                                                                                           $keptIssues
     * @return list<array<string, mixed>>
     */
    public function evaluateLines(User $user, Wallet $wallet, array $lines, array $pinnedWallets = [], array $keptIssues = []): array
    {
        ksort($lines);

        $code     = (string) $wallet->currency->code;
        $decimals = Money::decimals($code);
        $rate     = $this->wallets->fxRate($wallet);

        $lookups = [];

        foreach ($lines as $lineNo => $line) {
            if (mb_strlen($line['recipient']) <= self::RECIPIENT_MAX_LENGTH) {
                $lookups[$lineNo] = ['recipient' => $line['recipient'], 'currency' => $line['currency']];
            }
        }

        $resolutions = $this->resolver->resolveMany($user, $wallet, $lookups);
        $rows        = [];

        foreach ($lines as $lineNo => $line) {
            $resolution = $resolutions[$lineNo] ?? null;

            $row = [
                'line_no'             => (int) $lineNo,
                'recipient_input'     => mb_substr($line['recipient'], 0, self::RECIPIENT_MAX_LENGTH),
                'recipient_type'      => $resolution?->type,
                'currency_input'      => $line['currency']  === null ? null : mb_substr($line['currency'], 0, self::CURRENCY_MAX_LENGTH),
                'client_reference'    => $line['reference'] === null ? null : mb_substr($line['reference'], 0, self::REFERENCE_MAX_LENGTH),
                'recipient_user_id'   => null,
                'recipient_wallet_id' => null,
                'recipient_label'     => $resolution === null ? null : mb_substr($resolution->label, 0, self::RECIPIENT_MAX_LENGTH),
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
            ];

            $issue = $keptIssues[$lineNo] ?? match (true) {
                $resolution === null                                         => MassPaymentIssue::InvalidIdentifier,
                ! $resolution->isResolved()                                  => $resolution->issue ?? MassPaymentIssue::UnknownRecipient,
                $this->breaksPin($pinnedWallets, (int) $lineNo, $resolution) => MassPaymentIssue::RecipientChanged,
                default                                                      => $this->amountIssue($line['amount'], $code),
            };

            if ($issue === null && $line['note'] !== null && mb_strlen($line['note']) > MassPaymentCsvParser::NOTE_MAX_LENGTH) {
                $issue = MassPaymentIssue::NoteTooLong;
            }

            $figures = null;

            if ($issue === null) {
                $figures = $this->figures($wallet, $line['amount'], $decimals);
                $issue   = $this->limitIssue($wallet, $figures);
            }

            if ($issue !== null) {
                $hint = ! isset($keptIssues[$lineNo]) && $resolution !== null && $issue === $resolution->issue ? $resolution->hint : null;

                $rows[] = array_merge($row, [
                    'issue_code' => $issue->value,
                    'metadata'   => $hint === null ? null : ['issue_hint' => $hint->value],
                ]);

                continue;
            }

            $rows[] = array_merge($row, $figures, [
                'recipient_user_id'   => (int) $resolution->user->id,
                'recipient_wallet_id' => (int) $resolution->wallet->id,
                'exchange_rate'       => $rate,
                'status'              => MassPaymentRowStatus::Valid->value,
            ]);
        }

        return $this->withWarnings($rows);
    }

    /**
     * The preview fingerprint: a sha256 over the wallet, the currency and,
     * line by line, everything that decides who is paid what. It catches two
     * recipients swapped at the same total.
     *
     * Figures are canonicalised to eight places, so the fingerprint built
     * from freshly priced lines equals the one rebuilt from the stored rows.
     *
     * @param iterable<array<string, mixed>|MassPaymentRow> $rows
     */
    public function fingerprint(int $walletId, int $currencyId, iterable $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $value = fn (string $key): mixed => $row instanceof MassPaymentRow ? $row->getAttribute($key) : ($row[$key] ?? null);

            $line = [
                (int) $value('line_no'),
                (string) $value('status'),
                $value('recipient_wallet_id') === null ? null : (int) $value('recipient_wallet_id'),
            ];

            foreach (self::FIGURES as $figure) {
                $amount = $value($figure);
                $line[] = $amount === null ? null : DecimalMoney::normalize($amount, DecimalMoney::CALCULATION_SCALE);
            }

            $line[] = $value('note') === null ? null : (string) $value('note');

            $lines[(int) $value('line_no')] = $line;
        }

        ksort($lines);

        return hash('sha256', json_encode(
            [$walletId, $currencyId, array_values($lines)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** Seam for tests: the batch reference to use for a new draft. */
    protected function newReference(): string
    {
        return MassPaymentBatch::generateReference();
    }

    /**
     * Seam for tests: the batch confirm starts from, read in the owner's
     * scope with no lock. A race test returns a stale copy from here.
     *
     * @throws ModelNotFoundException for another user's batch or a payout batch
     */
    protected function findBatchForConfirm(User $user, string $uuid): MassPaymentBatch
    {
        return $user->massPaymentBatches()->ofKind(MassPaymentKind::Wallet)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Both per-user switches (send money and mass payment) and the module's
     * own access rule for the user's panel, checked again here because a
     * service that moves money does not rely on its route's middleware.
     *
     * @throws NotifyErrorException
     */
    public function assertFeatureAvailable(User $user): void
    {
        if (! $user->hasFeature('send_money') || ! $user->hasFeature(MassPaymentRecipientResolver::FEATURE_KEY)) {
            throw new NotifyErrorException(__('You do not have permission to access this feature.'), 403);
        }

        if (! $this->features->isAccessible(MassPaymentRecipientResolver::FEATURE_KEY, null, $user)) {
            throw new NotifyErrorException(__('Mass payments are not available for your account.'), 403);
        }
    }

    /**
     * Write the re-validated lines, the totals and the fingerprint over a
     * draft, under the batch lock and only while it is still a draft. A
     * confirm that lost a race therefore never rewrites lines another confirm
     * has already reserved. Returns null when the batch is no longer a draft.
     *
     * @param list<array<string, mixed>> $fresh
     */
    private function refreshDraft(User $user, MassPaymentBatch $batch, Wallet $wallet, array $fresh, string $fingerprint): ?MassPaymentBatch
    {
        return DB::transaction(function () use ($user, $batch, $wallet, $fresh, $fingerprint): ?MassPaymentBatch {
            $draft = MassPaymentBatch::query()
                ->whereKey($batch->id)
                ->where('user_id', $user->id)
                ->ofKind(MassPaymentKind::Wallet)
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

            $draft->forceFill(array_merge(
                $this->totals($fresh, (string) $wallet->currency->code),
                ['preview_fingerprint' => $fingerprint],
            ))->save();

            $this->recordEvent($draft, MassPaymentBatchStatus::Draft->value, MassPaymentActor::user($user), 'refreshed', [
                'changed_rows'         => $changed,
                'previous_fingerprint' => $previous,
            ]);

            return $draft->refresh();
        }, 3);
    }

    /**
     * The gates a batch must pass to be confirmed. Invalid lines block unless
     * the customer chose to skip them; duplicate warnings block until they
     * are acknowledged; the row cap and the batch cap (in base currency, on
     * the lines' base amounts) apply as they stand now.
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
            $validCount < 1                                           => MassPaymentNotConfirmableException::NO_VALID_ROWS,
            $invalidCount > 0 && ! $confirmation->skipInvalid         => MassPaymentNotConfirmableException::INVALID_ROWS,
            $warningCount > 0 && ! $confirmation->acknowledgeWarnings => MassPaymentNotConfirmableException::WARNINGS_UNACKNOWLEDGED,
            $rowCount     > $this->maxRows()                          => MassPaymentNotConfirmableException::ROW_CAP,
            $this->exceedsBatchAmountCap($totalBaseAmount)            => MassPaymentNotConfirmableException::BATCH_AMOUNT_CAP,
            default                                                   => null,
        };

        if ($reason !== null) {
            throw new MassPaymentNotConfirmableException($reason, $batch);
        }
    }

    /**
     * The stored lines as the customer typed them, keyed by line number, in
     * the shape {@see self::evaluateLines()} reads.
     *
     * @param  Collection<int, MassPaymentRow>                                                                                        $rows
     * @return array<int, array{recipient: string, amount: string, note: string|null, reference: string|null, currency: string|null}>
     */
    private function linesOf(Collection $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[(int) $row->line_no] = [
                'recipient' => (string) $row->recipient_input,
                'amount'    => (string) $row->amount_input,
                'note'      => $row->note,
                'reference' => $row->client_reference,
                'currency'  => $row->currency_input,
            ];
        }

        return $lines;
    }

    /**
     * The wallet each valid line was pinned to. A valid line whose wallet has
     * since been deleted pins to 0, which nothing resolves to.
     *
     * @param  Collection<int, MassPaymentRow> $rows
     * @return array<int, int>
     */
    private function pinnedWalletsOf(Collection $rows): array
    {
        $pins = [];

        foreach ($rows as $row) {
            if ($row->status() === MassPaymentRowStatus::Valid) {
                $pins[(int) $row->line_no] = (int) ($row->recipient_wallet_id ?? 0);
            }
        }

        return $pins;
    }

    /**
     * The issues a line keeps at confirm. Besides {@see self::KEPT_ISSUES}, a
     * recipient the draft cut to the column's length keeps its
     * `invalid_identifier`: the cut string is not what was typed.
     *
     * @param  Collection<int, MassPaymentRow> $rows
     * @return array<int, MassPaymentIssue>
     */
    private function keptIssuesOf(Collection $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $issue = $row->issue();

            $isCutRecipient = $issue === MassPaymentIssue::InvalidIdentifier
                && mb_strlen((string) $row->recipient_input) >= self::RECIPIENT_MAX_LENGTH;

            if ($issue !== null && (in_array($issue, self::KEPT_ISSUES, true) || $isCutRecipient)) {
                $kept[(int) $row->line_no] = $issue;
            }
        }

        return $kept;
    }

    /**
     * Why a typed amount cannot be paid, or null when it can.
     *
     * The currency's own rules (smallest unit, decimal places) plus a plain
     * digits-and-point shape: an amount with more decimals than the currency
     * holds is `too_many_decimals`; anything else that fails is
     * `invalid_amount`.
     */
    private function amountIssue(string $raw, string $code): ?MassPaymentIssue
    {
        if ($raw === '' || mb_strlen($raw) > self::AMOUNT_MAX_LENGTH) {
            return MassPaymentIssue::InvalidAmount;
        }

        $validator = Validator::make(
            ['amount' => $raw],
            ['amount' => array_merge(AmountEntry::rules($code), ['regex:'.self::AMOUNT_PATTERN])],
        );

        $hasShape = preg_match(self::AMOUNT_PATTERN, $raw) === 1;

        if ($validator->fails()) {
            $failed = array_keys($validator->failed()['amount'] ?? []);

            return $hasShape && in_array('Decimal', $failed, true)
                ? MassPaymentIssue::TooManyDecimals
                : MassPaymentIssue::InvalidAmount;
        }

        return $this->integerDigits($raw) > self::MAX_TYPED_INTEGER_DIGITS ? MassPaymentIssue::InvalidAmount : null;
    }

    /**
     * Whether a resolved line now points at a wallet other than the one it
     * was pinned to. A line with no pin has nothing to break.
     *
     * @param array<int, int> $pinnedWallets
     */
    private function breaksPin(array $pinnedWallets, int $lineNo, RecipientResolution $resolution): bool
    {
        return array_key_exists($lineNo, $pinnedWallets)
            && $pinnedWallets[$lineNo] !== (int) $resolution->wallet?->id;
    }

    /**
     * One line's frozen figures from {@see SendMoneyService::price()},
     * normalised to decimal strings.
     *
     * The base figures keep the calculation scale, as send money's own ledger
     * row keeps them. Cutting them to the base currency's places truncated a
     * 1.5% fee of 0.04995 to 0.04 while the wallet was charged 0.05, so the
     * fee revenue the dashboard sums from base_fee fell short of what was
     * collected by up to a minor unit a line.
     *
     * @return array{entered_amount: string, base_amount: string, base_fee: string, net_amount: string, payable_amount: string, wallet_fee: string}
     */
    private function figures(Wallet $wallet, string $raw, int $decimals): array
    {
        $price = $this->sendMoney->price($wallet, DecimalMoney::normalize($raw, $decimals));

        $net     = DecimalMoney::normalize($price['net'], $decimals);
        $payable = DecimalMoney::normalize($price['payable'], $decimals);

        return [
            'entered_amount' => DecimalMoney::normalize($price['entered'], $decimals),
            'base_amount'    => DecimalMoney::normalize($price['amount'], DecimalMoney::CALCULATION_SCALE),
            'base_fee'       => DecimalMoney::normalize($price['fee'], DecimalMoney::CALCULATION_SCALE),
            'net_amount'     => $net,
            'payable_amount' => $payable,
            'wallet_fee'     => DecimalMoney::subtract($payable, $net, $decimals),
        ];
    }

    /**
     * The sender role's limits on the line's base amount, fee excluded as
     * send money excludes it, or a figure too large to store.
     *
     * @param array<string, string> $figures
     */
    private function limitIssue(Wallet $wallet, array $figures): ?MassPaymentIssue
    {
        foreach ($figures as $figure) {
            if ($this->integerDigits($figure) > self::MAX_STORED_INTEGER_DIGITS) {
                return MassPaymentIssue::AboveLimit;
            }
        }

        try {
            $this->wallets->validateAmountLimitByRole($wallet, $figures['base_amount'], CurrencyRole::SENDER);
        } catch (NotifyErrorException) {
            $min = $this->wallets->amountLimitsByRole($wallet, CurrencyRole::SENDER)['min'];

            return $min !== null && DecimalMoney::compare($figures['base_amount'], $min) < 0
                ? MassPaymentIssue::BelowLimit
                : MassPaymentIssue::AboveLimit;
        }

        return null;
    }

    /**
     * Attach the duplicate warnings to the valid lines. Each warning names a
     * line it repeats: a later line points at the first, the first at the
     * next one.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withWarnings(array $rows): array
    {
        $groups = [
            MassPaymentWarning::DuplicateRecipient->value => [],
            MassPaymentWarning::DuplicateLine->value      => [],
            MassPaymentWarning::DuplicateReference->value => [],
        ];

        foreach ($rows as $index => $row) {
            if ($row['status'] !== MassPaymentRowStatus::Valid->value) {
                continue;
            }

            $groups[MassPaymentWarning::DuplicateRecipient->value][(string) $row['recipient_wallet_id']][] = $index;
            $groups[MassPaymentWarning::DuplicateLine->value][json_encode([
                $row['recipient_wallet_id'],
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

    /** The batch's `metadata.ledger_anomaly`, set when finalize found its ledger did not add up. */
    private function hasLedgerAnomaly(MassPaymentBatch $batch): bool
    {
        $metadata = is_array($batch->metadata) ? $batch->metadata : [];

        return ($metadata['ledger_anomaly'] ?? null) !== null;
    }

    /**
     * The batch's lines flagged for a ledger anomaly: still pending, and
     * skipped by every runner until an admin closes them after review.
     *
     * @return Builder<MassPaymentRow>
     */
    private function flaggedRows(MassPaymentBatch $batch): Builder
    {
        return MassPaymentRow::query()
            ->where('mass_payment_batch_id', $batch->id)
            ->where('status', MassPaymentRowStatus::Pending->value)
            ->where('needs_review', true);
    }

    /** An account that may move money: active and not suspended. */
    private function canTransact(?User $user): bool
    {
        return $user !== null
            && $user->status === UserStatus::ACTIVE
            && ! $user->isSuspended();
    }

    /** How many digits a plain decimal string has before its point, leading zeros ignored. */
    private function integerDigits(string $value): int
    {
        $integer = ltrim(explode('.', ltrim($value, '-+'))[0], '0');

        return strlen($integer);
    }

    protected function batchKind(): MassPaymentKind
    {
        return MassPaymentKind::Wallet;
    }
}
