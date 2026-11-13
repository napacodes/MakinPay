<?php

declare(strict_types=1);

namespace App\Models\P2P;

use App\Models\Currency;
use App\Services\P2P\P2PFeeResolver;
use Database\Factories\P2P\P2PAssetFeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-asset override of the global P2P maker/taker fee.
 *
 * P2P fees are charged in the asset being traded, so a single global figure
 * cannot serve BTC and USDT at once. A currency with no active row here uses
 * the global pair on `p2p_settings`; see {@see P2PFeeResolver}.
 */
class P2PAssetFee extends Model
{
    /** @use HasFactory<P2PAssetFeeFactory> */
    use HasFactory;

    protected $table = 'p2p_asset_fees';

    protected $fillable = [
        'currency_id',
        'maker_fee_pct',
        'maker_fee_fixed',
        'maker_fee_min',
        'maker_fee_max',
        'taker_fee_pct',
        'taker_fee_fixed',
        'taker_fee_min',
        'taker_fee_max',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'currency_id' => 'integer',
            // Exact decimals: a flat fee of 0.00005 BTC is a real configuration
            // and a float cast rounds it out of existence.
            'maker_fee_pct'   => 'decimal:4',
            'maker_fee_fixed' => 'decimal:8',
            'maker_fee_min'   => 'decimal:8',
            'maker_fee_max'   => 'decimal:8',
            'taker_fee_pct'   => 'decimal:4',
            'taker_fee_fixed' => 'decimal:8',
            'taker_fee_min'   => 'decimal:8',
            'taker_fee_max'   => 'decimal:8',
            'is_active'       => 'boolean',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
