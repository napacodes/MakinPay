<?php

namespace App\Models;

use App\Enums\WalletEarnPayoutFrequency;
use App\Enums\WalletEarnProfitType;
use App\Enums\WalletEarnStatus;
use App\Traits\Models\SyncsProfitComponents;
use Database\Factories\WalletEarnStakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletEarnStake extends Model
{
    /** @use HasFactory<WalletEarnStakeFactory> */
    use HasFactory;

    use SyncsProfitComponents;

    protected $fillable = [
        'user_id',
        'wallet_earn_plan_id',
        'wallet_id',
        'currency_id',
        'reviewed_by',
        'plan_name',
        'principal_amount',
        'profit_rate',
        'profit_type',
        'profit_fixed',
        'profit_percent',
        'profit_min',
        'profit_max',
        'duration_value',
        'duration_unit',
        'payout_frequency',
        'return_principal',
        'auto_restake',
        'restaked_into_id',
        'expected_profit',
        'paid_profit',
        'total_payouts',
        'payouts_made',
        'status',
        'trx_id',
        'review_note',
        'starts_at',
        'next_payout_at',
        'matures_at',
        'completed_at',
        'canceled_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'float',
            'profit_rate'      => 'float',
            'profit_type'      => WalletEarnProfitType::class,
            // The stake snapshots its terms at open time, so it carries the
            // same exact-decimal components the plan does.
            'profit_fixed'     => 'decimal:8',
            'profit_percent'   => 'decimal:8',
            'profit_min'       => 'decimal:8',
            'profit_max'       => 'decimal:8',
            'payout_frequency' => WalletEarnPayoutFrequency::class,
            'auto_restake'     => 'boolean',
            'return_principal' => 'boolean',
            'expected_profit'  => 'float',
            'paid_profit'      => 'float',
            'total_payouts'    => 'integer',
            'payouts_made'     => 'integer',
            'status'           => WalletEarnStatus::class,
            'starts_at'        => 'datetime',
            'next_payout_at'   => 'datetime',
            'matures_at'       => 'datetime',
            'completed_at'     => 'datetime',
            'canceled_at'      => 'datetime',
            'rejected_at'      => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WalletEarnPlan::class, 'wallet_earn_plan_id')->withDefault();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(WalletEarnReward::class);
    }

    public function isReviewable(): bool
    {
        return $this->status === WalletEarnStatus::Pending;
    }

    public function isActive(): bool
    {
        return $this->status === WalletEarnStatus::Active;
    }

    public function canReturnPrincipal(): bool
    {
        return $this->return_principal && ! $this->status->isTerminal();
    }
}
