<?php

namespace App\Models;

use Database\Factories\MerchantApiIdempotencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantApiIdempotency extends Model
{
    /** @use HasFactory<MerchantApiIdempotencyFactory> */
    use HasFactory, MassPrunable;

    protected $fillable = [
        'merchant_id',
        'environment',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_content_type',
        'response_body',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at'      => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
