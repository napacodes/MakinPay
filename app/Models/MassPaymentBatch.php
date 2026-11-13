<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use Database\Factories\MassPaymentBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mass payment: a merchant paying many wallets from one source wallet in
 * one currency.
 *
 * Money is cast `decimal:8` (and the rate `decimal:18`) so it reads back at
 * the column's own scale as an exact string rather than a float.
 *
 * `status` is deliberately left uncast, matching BillPayment's
 * `dispatch_state`: {@see MassPaymentBatchStatus} supplies the vocabulary for
 * writing it, and {@see self::status()} reads it back. An unrecognised stored
 * value reads as NeedsReview, so nothing acts on a batch it does not
 * understand.
 *
 * Every customer read goes through {@see User::massPaymentBatches()}, so a
 * batch is only ever found inside its owner's scope, and is narrowed by
 * {@see self::scopeOfKind()}, so a wallet route never finds a payout batch.
 */
class MassPaymentBatch extends Model
{
    /** @use HasFactory<MassPaymentBatchFactory> */
    use HasFactory;

    public const REFERENCE_PREFIX = 'MP';

    /** Crockford base 32: no I, L, O or U, so a reference reads back unambiguously. */
    public const REFERENCE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const REFERENCE_LENGTH = 10;

    /** A queued or processing batch with no progress for this long needs a look. */
    public const STALLED_AFTER_MINUTES = 30;

    /**
     * A new batch is a draft. Defaulting it here also keeps `status` present
     * on an unsaved model, so reading `$batch->status` never falls through to
     * the {@see self::status()} method as if it were a relation.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'kind'   => 'wallet',
    ];

    protected $fillable = [
        'uuid',
        'reference',
        'name',
        'user_id',
        'wallet_id',
        'currency_id',
        'currency_code',
        'kind',
        'source',
        'channel',
        'scheduled_payment_id',
        'original_filename',
        'status',
        'skip_invalid',
        'warnings_acknowledged',
        'row_count',
        'valid_count',
        'invalid_count',
        'warning_count',
        'paid_count',
        'failed_count',
        'total_amount',
        'total_fee',
        'total_payable',
        'total_base_amount',
        'total_base_fee',
        'paid_payable',
        'paid_base_fee',
        'released_amount',
        'exchange_rate',
        'preview_fingerprint',
        'stop_requested_at',
        'stop_requested_by_type',
        'stop_requested_by_id',
        'hold_reason',
        'reviewed_by_admin_id',
        'admin_note',
        'expires_at',
        'confirmed_at',
        'processing_started_at',
        'last_progress_at',
        'completed_at',
        'metadata',
        'withdraw_method_id',
        'payout_driver',
        'approval_required',
        'approved_by_admin_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'skip_invalid'          => 'boolean',
            'warnings_acknowledged' => 'boolean',

            'row_count'     => 'integer',
            'valid_count'   => 'integer',
            'invalid_count' => 'integer',
            'warning_count' => 'integer',
            'paid_count'    => 'integer',
            'failed_count'  => 'integer',

            'total_amount'      => 'decimal:8',
            'total_fee'         => 'decimal:8',
            'total_payable'     => 'decimal:8',
            'total_base_amount' => 'decimal:8',
            'total_base_fee'    => 'decimal:8',
            'paid_payable'      => 'decimal:8',
            'paid_base_fee'     => 'decimal:8',
            'released_amount'   => 'decimal:8',
            'exchange_rate'     => 'decimal:18',

            'stop_requested_by_id' => 'integer',
            'metadata'             => 'array',

            'stop_requested_at'     => 'datetime',
            'expires_at'            => 'datetime',
            'confirmed_at'          => 'datetime',
            'processing_started_at' => 'datetime',
            'last_progress_at'      => 'datetime',
            'completed_at'          => 'datetime',

            'withdraw_method_id'   => 'integer',
            'approval_required'    => 'boolean',
            'approved_by_admin_id' => 'integer',
            'approved_at'          => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * A human-readable reference, `MP` plus ten Crockford base-32 characters,
     * that no existing batch carries yet.
     *
     * The existence check only makes a collision unlikely; the unique index on
     * `reference` is what actually refuses one.
     */
    public static function generateReference(): string
    {
        $alphabetSize = strlen(self::REFERENCE_ALPHABET);

        do {
            $reference = self::REFERENCE_PREFIX;

            for ($index = 0; $index < self::REFERENCE_LENGTH; $index++) {
                $reference .= self::REFERENCE_ALPHABET[random_int(0, $alphabetSize - 1)];
            }
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * The stored status as the enum. An unrecognised value reads as
     * NeedsReview, so nothing acts on a batch it does not understand.
     */
    public function status(): MassPaymentBatchStatus
    {
        return MassPaymentBatchStatus::tryFrom((string) $this->getAttribute('status'))
            ?? MassPaymentBatchStatus::NeedsReview;
    }

    /**
     * The stored kind as the enum. Only the exact value `wallet` reads as a
     * wallet batch, matching {@see self::scopeOfKind()}; anything else reads
     * as Payout, so no wallet flow ever acts on a batch it does not
     * understand.
     */
    public function kind(): MassPaymentKind
    {
        return MassPaymentKind::tryFrom((string) $this->getAttribute('kind')) ?? MassPaymentKind::Payout;
    }

    public function isPayout(): bool
    {
        return $this->kind() === MassPaymentKind::Payout;
    }

    /**
     * Only batches of this kind. Every customer and service lookup is scoped
     * by it, so a wallet route never finds a payout batch and the reverse.
     */
    public function scopeOfKind(Builder $query, MassPaymentKind $kind): Builder
    {
        return $query->where($this->qualifyColumn('kind'), $kind->value);
    }

    /**
     * The schedule that rang this batch up, when one did.
     *
     * @return BelongsTo<ScheduledPayment, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScheduledPayment::class, 'scheduled_payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The source wallet the whole batch is paid from. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(MassPaymentRow::class)->orderBy('line_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MassPaymentEvent::class)->orderBy('id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    /** The admin who approved a payout batch that waited for approval. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    /**
     * The withdraw method a payout batch sends every line through. Null for
     * a wallet batch, and for a payout batch whose method was later deleted:
     * the column is deliberately not a foreign key, and the terms the batch
     * was built on live in `metadata.payout`, never in this live record.
     */
    public function withdrawMethod(): BelongsTo
    {
        return $this->belongsTo(WithdrawMethod::class);
    }

    /**
     * The one definition of "a mass payment someone has to act on". The admin
     * list, the dashboard queue and the sidebar badge all read it.
     *
     * A held batch always counts, and so does a payout batch waiting for an
     * admin's approval or with a line waiting for an operator or under
     * review. So does a queued or processing batch that has made no
     * progress for {@see self::STALLED_AFTER_MINUTES} minutes, measured from
     * its last progress or, before any, from its confirmation, so a stalled
     * payout stays visible instead of waiting silently.
     */
    public function scopeAwaitingAction(Builder $query): Builder
    {
        $cutoff = now()->subMinutes(self::STALLED_AFTER_MINUTES);

        $status   = $this->qualifyColumn('status');
        $progress = $this->qualifyColumn('last_progress_at');
        $confirm  = $this->qualifyColumn('confirmed_at');

        $quietSinceCutoff = fn (Builder $since): Builder => $since
            ->where($progress, '<', $cutoff)
            ->orWhere(fn (Builder $never): Builder => $never
                ->whereNull($progress)
                ->where(fn (Builder $confirmed): Builder => $confirmed
                    ->whereNull($confirm)
                    ->orWhere($confirm, '<', $cutoff)));

        return $query->where(fn (Builder $awaiting): Builder => $awaiting
            ->whereIn($status, [MassPaymentBatchStatus::NeedsReview->value, MassPaymentBatchStatus::AwaitingApproval->value])
            ->orWhereExists(fn ($lines) => $lines
                ->selectRaw('1')
                ->from('mass_payment_rows')
                ->whereColumn('mass_payment_rows.mass_payment_batch_id', $this->qualifyColumn('id'))
                ->where('mass_payment_rows.status', MassPaymentRowStatus::Pending->value)
                ->whereIn('mass_payment_rows.dispatch_state', [
                    MassPaymentDispatchState::AwaitingOperator->value,
                    MassPaymentDispatchState::NeedsReview->value,
                ]))
            ->orWhere(fn (Builder $stalled): Builder => $stalled
                ->whereIn($status, [
                    MassPaymentBatchStatus::Queued->value,
                    MassPaymentBatchStatus::Processing->value,
                ])
                ->where($quietSinceCutoff)));
    }
}
