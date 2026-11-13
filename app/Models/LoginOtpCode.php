<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LoginOtpCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time sign-in code sent by SMS (GF9). Only its hash is kept.
 */
class LoginOtpCode extends Model
{
    /** @use HasFactory<LoginOtpCodeFactory> */
    use HasFactory, MassPrunable;

    protected $fillable = [
        'user_id',
        'phone_number',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'requested_ip',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts'    => 'integer',
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A day past expiry a code is no use to anyone, used or not.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now()->subDay());
    }

    /**
     * Not yet used and not yet expired.
     *
     * @param Builder<self> $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
