<?php

declare(strict_types=1);

namespace App\Models\P2P;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A search the customer asked to be told about: the side, the pair, and
 * optionally a method and an amount. Told once, then it is spent.
 */
class MarketAlert extends Model
{
    protected $table = 'p2p_market_alerts';

    protected $fillable = ['user_id', 'side', 'asset', 'fiat', 'payment_method_id', 'amount', 'notified_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'notified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
