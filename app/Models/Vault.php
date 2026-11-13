<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A savings goal: money set aside from a wallet into a ring-fenced balance
 * of the same currency, with the rules that top it up on their own.
 */
class Vault extends Model
{
    public const ICONS = ['piggy-bank', 'plane', 'home', 'car', 'laptop', 'gift', 'heart', 'graduation-cap', 'shield', 'umbrella', 'baby', 'bike', 'camera', 'gamepad-2', 'music', 'palmtree', 'gem', 'dog', 'wrench', 'sparkles'];

    public const FREQUENCIES = ['weekly', 'monthly'];

    protected $fillable = ['user_id', 'wallet_id', 'name', 'icon', 'target_amount', 'target_date', 'balance', 'status', 'auto_amount', 'auto_frequency', 'auto_day', 'round_ups', 'round_up_to', 'next_auto_at', 'last_round_up_at', 'paused_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'target_amount'    => 'decimal:8',
            'balance'          => 'decimal:8',
            'auto_amount'      => 'decimal:8',
            'round_up_to'      => 'decimal:8',
            'target_date'      => 'date',
            'auto_day'         => 'integer',
            'round_ups'        => 'boolean',
            'next_auto_at'     => 'datetime',
            'last_round_up_at' => 'datetime',
            'paused_at'        => 'datetime',
            'closed_at'        => 'datetime',
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

    public function entries(): HasMany
    {
        return $this->hasMany(VaultEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }
}
