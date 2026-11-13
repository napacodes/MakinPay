<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook the platform owes a merchant, and what became of it.
 *
 * `payload` holds the exact JSON bytes that are signed on every attempt, so
 * the merchant verifies the same signature whether the first try or the
 * sixth reached them.
 */
class MerchantWebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'merchant_id',
        'transaction_id',
        'trx_id',
        'event',
        'environment',
        'url',
        'payload',
        'status',
        'attempts',
        'last_status_code',
        'last_error',
        'next_attempt_at',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts'         => 'integer',
            'last_status_code' => 'integer',
            'next_attempt_at'  => 'datetime',
            'delivered_at'     => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
