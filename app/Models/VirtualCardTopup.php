<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VirtualCard\VirtualCardTopupDispatchState;
use App\Enums\VirtualCard\VirtualCardTopupStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to move money from a wallet onto a virtual card.
 *
 * Money is cast `decimal:8` so it reads back at the column's own scale rather
 * than as a float, the same reason Transaction does it.
 *
 * `dispatch_state` is deliberately left uncast: it is a plain string column,
 * matching RemittanceTransfer, and VirtualCardTopupDispatchState supplies the
 * vocabulary for writing it.
 */
class VirtualCardTopup extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_id',
        'virtual_card_id',
        'transaction_id',
        'amount',
        'fee',
        'total_amount',
        'card_amount',
        'currency',
        'card_currency',
        'exchange_rate',
        'provider',
        'provider_reference',
        'provider_transaction_id',
        'status',
        'dispatch_state',
        'client_idempotency_key',
        'client_request_hash',
        'failure_reason',
        'metadata',
        'submitted_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:8',
            'fee'           => 'decimal:8',
            'total_amount'  => 'decimal:8',
            'card_amount'   => 'decimal:8',
            'exchange_rate' => 'decimal:12',
            'status'        => VirtualCardTopupStatus::class,
            'metadata'      => 'array',
            'submitted_at'  => 'datetime',
            'processed_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(VirtualCard::class, 'virtual_card_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function dispatchState(): VirtualCardTopupDispatchState
    {
        return VirtualCardTopupDispatchState::tryFrom((string) $this->dispatch_state)
            ?? VirtualCardTopupDispatchState::NeedsReview;
    }

    /**
     * Attempts a recovery sweep still has something to decide about.
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNotIn('dispatch_state', [
            VirtualCardTopupDispatchState::Settled->value,
            VirtualCardTopupDispatchState::Refunded->value,
        ]);
    }
}
