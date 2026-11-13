<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment the customer asked to have made for them, once or on a
 * cadence: a send to somebody, a top-up of a vault, or a recharge. The
 * scheduler runs it; the mandate is the PIN they entered when they set it.
 */
class ScheduledPayment extends Model
{
    /** send, vault, recharge, and mass - a whole mass payment batch, paid again on a cadence. */
    public const TYPES = ['send', 'vault', 'recharge', 'mass'];

    public const CADENCES = ['once', 'weekly', 'monthly', 'custom'];

    public const WEEKEND_RULES = ['none', 'before', 'after'];

    public const LOW_BALANCE = ['skip', 'retry', 'top_up'];

    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'user_id', 'type', 'wallet_id', 'amount', 'currency', 'recipient', 'recipient_name', 'vault_id', 'payload',
        'cadence', 'interval_days', 'weekdays', 'day_of_month', 'starts_on', 'ends_on', 'weekend_rule',
        'low_balance', 'retry_days', 'top_up_wallet_id', 'remind', 'remind_at', 'note', 'status',
        'next_run_at', 'last_run_at', 'last_status', 'failed_reason', 'retry_count', 'reminded_for', 'mandate_id', 'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:8',
            'payload'       => 'array',
            'weekdays'      => 'array',
            'interval_days' => 'integer',
            'day_of_month'  => 'integer',
            'starts_on'     => 'date',
            'ends_on'       => 'date',
            'retry_days'    => 'integer',
            'remind'        => 'boolean',
            'next_run_at'   => 'datetime',
            'last_run_at'   => 'datetime',
            'retry_count'   => 'integer',
            'reminded_for'  => 'datetime',
            'paused_at'     => 'datetime',
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

    public function topUpWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'top_up_wallet_id');
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledPaymentRun::class);
    }

    public function isRecurring(): bool
    {
        return $this->cadence !== 'once';
    }
}
