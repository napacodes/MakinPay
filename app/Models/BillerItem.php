<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BillerItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A package a fixed-amount biller sells, priced in the biller's currency.
 */
class BillerItem extends Model
{
    /** @use HasFactory<BillerItemFactory> */
    use HasFactory;

    protected $fillable = [
        'biller_id',
        'name',
        'external_code',
        'amount',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:8',
            'status'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function biller(): BelongsTo
    {
        return $this->belongsTo(Biller::class);
    }
}
