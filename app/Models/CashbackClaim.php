<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month of card-spend cashback for one customer, in the site currency:
 * how much was spent, at what rate, what that earns, and until when it can
 * be claimed. A claim credits the default wallet once and keeps the
 * transaction id; the unique key makes a second claim of the same month
 * find the first.
 */
class CashbackClaim extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['user_id', 'period', 'currency', 'spend', 'rate_pct', 'amount', 'status', 'claim_by', 'claimed_at', 'trx_id'];

    protected function casts(): array
    {
        return [
            'spend'      => 'decimal:8',
            'rate_pct'   => 'decimal:4',
            'amount'     => 'decimal:8',
            'claim_by'   => 'date',
            'claimed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pending on paper but past its claim-by date reads as expired.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->claim_by !== null && $this->claim_by->lt(Carbon::today())) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }
}
