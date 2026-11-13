<?php

namespace App\Models;

use App\Enums\MerchantSubscriptionInvoiceStatus;
use Database\Factories\MerchantSubscriptionInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MerchantSubscriptionInvoice extends Model
{
    /** @use HasFactory<MerchantSubscriptionInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'merchant_subscription_id',
        'sequence',
        'reference',
        'transaction_id',
        'status',
        'amount',
        'currency_code',
        'period_start',
        'period_end',
        'due_at',
        'checkout_expires_at',
        'payment_url',
        'paid_at',
        'failed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            $invoice->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'sequence'            => 'integer',
            'status'              => MerchantSubscriptionInvoiceStatus::class,
            'amount'              => 'decimal:8',
            'period_start'        => 'datetime',
            'period_end'          => 'datetime',
            'due_at'              => 'datetime',
            'checkout_expires_at' => 'datetime',
            'paid_at'             => 'datetime',
            'failed_at'           => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MerchantSubscription::class, 'merchant_subscription_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'trx_id');
    }
}
