<?php

namespace App\Models;

use App\Enums\VirtualCard\VirtualCardFeeOperation;
use App\Support\FeeCalculator;
use Illuminate\Database\Eloquent\Model;

class VirtualCardFeeSetting extends Model
{
    protected $fillable = [
        'provider_id',
        'currency_id',
        'operation',
        'fee_amount',     // fixed amount
        'fee_percent',    // percentage component
        'min_amount',
        'max_amount',
        'daily_txn_limit',
        'daily_amount_limit',
        'approval_threshold',
        'active',
    ];

    protected $casts = [
        'operation'          => VirtualCardFeeOperation::class,
        'fee_amount'         => 'float',
        'fee_percent'        => 'float',
        'min_amount'         => 'float',
        'max_amount'         => 'float',
        'daily_txn_limit'    => 'integer',
        'daily_amount_limit' => 'float',
        'approval_threshold' => 'float',
        'active'             => 'boolean',
    ];

    // Relationship with provider
    public function provider()
    {
        return $this->belongsTo(VirtualCardProvider::class, 'provider_id');
    }

    // Relationship with currency
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    // Calculate transaction fee as fixed + percentage
    public function calculateFee(float $amount): float
    {
        // Exact decimals, and at calculation scale rather than the hardcoded
        // 2 this used: a fee on a crypto card top-up rounded to cents lost the
        // whole charge.
        return app(FeeCalculator::class)->total($amount, $this->fee_amount, $this->fee_percent);
    }

    // Check if admin approval is required
    public function requiresAdminApproval(float $amount): bool
    {
        return $this->approval_threshold !== null && $amount > (float) $this->approval_threshold;
    }
}
