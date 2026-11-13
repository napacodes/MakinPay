<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Tell me when 1 FROM buys TARGET TO", optionally exchanging when it does.
 *
 * @property int                  $id
 * @property int                  $user_id
 * @property string               $from_currency
 * @property string               $to_currency
 * @property string               $target_rate
 * @property string               $direction             above or below
 * @property bool                 $auto_exchange
 * @property string|null          $amount
 * @property int|null             $source_wallet_id
 * @property int|null             $destination_wallet_id
 * @property string               $status                active, triggered or canceled
 * @property string|null          $triggered_rate
 * @property CarbonInterface|null $triggered_at
 */
class RateAlert extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRIGGERED = 'triggered';

    public const STATUS_CANCELED = 'canceled';

    public const DIRECTION_ABOVE = 'above';

    public const DIRECTION_BELOW = 'below';

    protected $fillable = [
        'user_id',
        'from_currency',
        'to_currency',
        'target_rate',
        'direction',
        'auto_exchange',
        'amount',
        'source_wallet_id',
        'destination_wallet_id',
        'status',
        'triggered_rate',
        'triggered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_exchange' => 'boolean',
            'triggered_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id');
    }

    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }

    /**
     * @param  Builder<RateAlert> $query
     * @return Builder<RateAlert>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether [$rate] - TO per one FROM - is on the side of the target the
     * customer asked to hear about.
     */
    public function isMetBy(string $rate): bool
    {
        $comparison = bccomp($rate, (string) $this->target_rate, 18);

        return $this->direction === self::DIRECTION_BELOW ? $comparison <= 0 : $comparison >= 0;
    }
}
