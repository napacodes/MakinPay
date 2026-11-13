<?php

namespace App\Models;

use App\Enums\PayoutMethod;
use App\Enums\RemittanceStatus;
use Carbon\Carbon;
use Database\Factories\RemittanceTransferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RemittanceTransfer extends Model
{
    /** @use HasFactory<RemittanceTransferFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'quote_id',
        'beneficiary_id',
        'corridor_id',
        'transaction_id',
        'refund_transaction_id',
        'source_currency',
        'destination_currency',
        'send_amount',
        'fee_amount',
        'tax_amount',
        'tax_breakdown',
        'total_paid',
        'exchange_rate',
        'receive_amount',
        'payout_method',
        'payout_gateway',
        'provider_idempotency_key',
        'client_idempotency_key',
        'client_request_hash',
        'recipient_snapshot',
        'dispatch_state',
        'dispatch_attempts',
        'last_dispatch_at',
        'last_reconciled_at',
        'reconciliation_attempts',
        'reconciliation_error',
        'reconciliation_alerted_at',
        'gateway_reference',
        'status',
        'status_history',
        'compliance_result',
        'gateway_payload',
        'purpose',
        'source_of_funds',
        'failure_reason',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payout_method'             => PayoutMethod::class,
            'status'                    => RemittanceStatus::class,
            'status_history'            => 'array',
            'compliance_result'         => 'array',
            'gateway_payload'           => 'array',
            'recipient_snapshot'        => 'array',
            'dispatch_attempts'         => 'integer',
            'reconciliation_attempts'   => 'integer',
            'send_amount'               => 'decimal:2',
            'fee_amount'                => 'decimal:2',
            'tax_amount'                => 'decimal:8',
            'tax_breakdown'             => 'array',
            'total_paid'                => 'decimal:2',
            'exchange_rate'             => 'decimal:8',
            'receive_amount'            => 'decimal:2',
            'submitted_at'              => 'datetime',
            'completed_at'              => 'datetime',
            'last_dispatch_at'          => 'datetime',
            'last_reconciled_at'        => 'datetime',
            'reconciliation_alerted_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (self $transfer): void {
            if (! $transfer->reference) {
                do {
                    $transfer->reference = 'RMT'.Str::upper(Str::random(12));
                } while (self::where('reference', $transfer->reference)->exists());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(RemittanceQuote::class, 'quote_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(RemittanceCorridor::class, 'corridor_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(RemittanceStatusEvent::class, 'transfer_id');
    }

    public function getRecipientNameAttribute(): string
    {
        return (string) (data_get($this->recipient_snapshot, 'full_name')
            ?: $this->beneficiary?->full_name
            ?: __('Beneficiary'));
    }

    public function getRecipientCountryAttribute(): string
    {
        return (string) (data_get($this->recipient_snapshot, 'country_code')
            ?: $this->beneficiary?->country_code
            ?: '—');
    }

    public function getMaskedRecipientAccountAttribute(): string
    {
        $details = (array) data_get($this->recipient_snapshot, 'account_details', []);
        $value   = (string) ($details['account_number']
            ?? $details['phone_number']
            ?? $details['card_number']
            ?? $details['wallet_uuid']
            ?? '');

        if ($value === '') {
            return (string) ($this->beneficiary?->masked_account ?? '—');
        }

        if (strlen($value) <= 4) {
            return $value;
        }

        return str_repeat('*', strlen($value) - 4).substr($value, -4);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordStatus(RemittanceStatus $status, ?string $note = null, array $context = []): void
    {
        $fromStatus = $this->status instanceof RemittanceStatus
            ? $this->status->value
            : (string) $this->status;
        $history   = $this->status_history ?? [];
        $isInitial = $history === [];
        $history[] = [
            'status'    => $status->value,
            'note'      => $note,
            'timestamp' => now()->toIso8601String(),
            'context'   => $context,
        ];

        $updates = ['status' => $status, 'status_history' => $history];

        if ($status === RemittanceStatus::Submitted && ! $this->submitted_at) {
            $updates['submitted_at'] = now();
        }

        if ($status === RemittanceStatus::Completed && ! $this->completed_at) {
            $updates['completed_at'] = now();
        }

        if ($status === RemittanceStatus::Failed && $note) {
            $updates['failure_reason'] = $note;
        }

        $this->update($updates);

        $this->statusEvents()->create([
            'from_status'       => $isInitial ? null : $fromStatus,
            'to_status'         => $status->value,
            'note'              => $note,
            'actor_type'        => $context['actor_type']        ?? 'system',
            'actor_id'          => $context['actor_id']          ?? null,
            'source'            => $context['source']            ?? 'system',
            'external_event_id' => $context['external_event_id'] ?? null,
            'payload_hash'      => $context['payload_hash']      ?? null,
            'context'           => $context,
            'occurred_at'       => $context['occurred_at'] ?? now(),
        ]);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeStatus(Builder $query, RemittanceStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof RemittanceStatus ? $status->value : $status);
    }

    public function getCreatedAtTimeAttribute(): string
    {
        return Carbon::parse($this->attributes['created_at'])->format('M d Y h:i A');
    }
}
