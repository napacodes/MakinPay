<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillPayment\BillPaymentDispatchState;
use App\Enums\BillPayment\BillPaymentFulfilment;
use App\Enums\BillPayment\BillPaymentStatus;
use Database\Factories\BillPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bill payment: the attempt, its state and the snapshot a receipt shows.
 *
 * Money is cast `decimal:8` so it reads back at the column's own scale rather
 * than as a float, the same reason Transaction and VirtualCardTopup do it.
 *
 * `dispatch_state` is deliberately left uncast, matching VirtualCardTopup and
 * RemittanceTransfer: it is a plain string column and
 * {@see BillPaymentDispatchState} supplies the vocabulary for writing it.
 * {@see self::dispatchState()} reads it back as the enum.
 *
 * `token` (a prepaid-meter token, a voucher PIN) is encrypted at rest and
 * hidden from serialisation. It is shown only on the owner's receipt and to
 * admins allowed to act on the payment, and is never copied into the ledger
 * row, notifications, events or logs.
 */
class BillPayment extends Model
{
    /** @use HasFactory<BillPaymentFactory> */
    use HasFactory;

    public const REFERENCE_PREFIX = 'BP';

    /** Crockford base 32: no I, L, O or U, so a reference reads back unambiguously. */
    public const REFERENCE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const REFERENCE_LENGTH = 10;

    protected $fillable = [
        'uuid',
        'reference',
        'user_id',
        'wallet_id',
        'biller_id',
        'biller_item_id',
        'bill_payment_provider_id',
        'transaction_id',
        'refund_transaction_id',
        'biller_name',
        'category_code',
        'item_name',
        'item_code',
        'country',
        'customer_number_label',
        'customer_number',
        'provider_code',
        'driver',
        'fulfilment',
        'bill_amount',
        'bill_currency',
        'amount',
        'fee',
        'tax_amount',
        'tax_breakdown',
        'total_amount',
        'currency',
        'exchange_rate',
        'status',
        'dispatch_state',
        'provider_request_id',
        'provider_transaction_id',
        'receipt_reference',
        'token',
        'client_idempotency_key',
        'client_request_hash',
        'channel',
        'failure_reason',
        'admin_note',
        'claimed_by_admin_id',
        'claimed_at',
        'resolved_by_admin_id',
        'metadata',
        'submitted_at',
        'processed_at',
        'provider_check_attempts',
        'provider_checked_at',
        'provider_next_check_at',
        'provider_final_status_at',
    ];

    protected $hidden = [
        'token',
        'client_idempotency_key',
        'client_request_hash',
    ];

    protected function casts(): array
    {
        return [
            'bill_amount'   => 'decimal:8',
            'amount'        => 'decimal:8',
            'fee'           => 'decimal:8',
            'tax_amount'    => 'decimal:8',
            'tax_breakdown' => 'array',
            'total_amount'  => 'decimal:8',
            'exchange_rate' => 'decimal:18',
            'status'        => BillPaymentStatus::class,
            'fulfilment'    => BillPaymentFulfilment::class,
            'token'         => 'encrypted',
            'metadata'      => 'array',
            'claimed_at'    => 'datetime',
            'submitted_at'  => 'datetime',
            'processed_at'  => 'datetime',

            'provider_check_attempts'  => 'integer',
            'provider_checked_at'      => 'datetime',
            'provider_next_check_at'   => 'datetime',
            'provider_final_status_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * A human-readable reference, `BP` plus ten Crockford base-32 characters,
     * that no existing payment carries yet.
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function biller(): BelongsTo
    {
        return $this->belongsTo(Biller::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillerItem::class, 'biller_item_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BillPaymentProvider::class, 'bill_payment_provider_id');
    }

    /** The wallet debit this payment reserved. */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillPaymentEvent::class)->orderBy('id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'claimed_by_admin_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by_admin_id');
    }

    /**
     * The stored dispatch state as the enum. An unrecognised value reads as
     * NeedsReview, so nothing acts on a row it does not understand.
     */
    public function dispatchState(): BillPaymentDispatchState
    {
        return BillPaymentDispatchState::tryFrom((string) $this->dispatch_state)
            ?? BillPaymentDispatchState::NeedsReview;
    }

    /**
     * The one definition of "a bill payment someone has to act on". The admin
     * queue, the dashboard panel and the sidebar badge all read it.
     *
     * Every open operator-fulfilled payment counts, and so does any open
     * payment that needs review or is still reserved, so a row stuck in
     * `reserved` after a crash stays visible instead of waiting silently.
     */
    public function scopeAwaitingAction(Builder $query): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), [
                BillPaymentStatus::Pending->value,
                BillPaymentStatus::Processing->value,
            ])
            ->where(fn (Builder $open): Builder => $open
                ->where($this->qualifyColumn('fulfilment'), BillPaymentFulfilment::Operator->value)
                ->orWhereIn($this->qualifyColumn('dispatch_state'), [
                    BillPaymentDispatchState::NeedsReview->value,
                    BillPaymentDispatchState::Reserved->value,
                ]));
    }
}
