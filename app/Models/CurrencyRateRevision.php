<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per currency per rebase, sharing a batch_uuid.
 */
class CurrencyRateRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_uuid',
        'currency_id',
        'currency_code',
        'old_rate',
        'new_rate',
        'old_base_code',
        'new_base_code',
        'reason',
        'admin_id',
    ];

    protected function casts(): array
    {
        return [
            'currency_id' => 'integer',
            'admin_id'    => 'integer',
            'old_rate'    => 'decimal:18',
            'new_rate'    => 'decimal:18',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
