<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\EnvironmentMode;
use App\Enums\MerchantSubscriptionStatus;
use Database\Factories\MerchantSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class MerchantSubscription extends Model
{
    /** @use HasFactory<MerchantSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'merchant_id',
        'environment',
        'external_reference',
        'status',
        'billing_cycle',
        'amount',
        'currency_code',
        'customer_name',
        'customer_email',
        'description',
        'success_redirect',
        'cancel_redirect',
        'ipn_url',
        'starts_at',
        'current_period_start',
        'current_period_end',
        'next_billing_at',
        'cancel_at_period_end',
        'cancelled_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            $subscription->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'environment'          => EnvironmentMode::class,
            'status'               => MerchantSubscriptionStatus::class,
            'billing_cycle'        => BillingCycle::class,
            'amount'               => 'decimal:8',
            'starts_at'            => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'next_billing_at'      => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at'         => 'datetime',
            'metadata'             => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantSubscriptionInvoice::class)->orderByDesc('sequence');
    }

    public function latestInvoice(): HasOne
    {
        return $this->hasOne(MerchantSubscriptionInvoice::class)->latestOfMany('sequence');
    }

    public function scopeForMerchantEnvironment(Builder $query, Merchant $merchant, EnvironmentMode $environment): Builder
    {
        return $query
            ->where('merchant_id', $merchant->id)
            ->where('environment', $environment->value);
    }
}
