<?php

namespace App\Models;

use App\Enums\CustomCodeType;
use App\Traits\Models\FlushesCacheAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CustomCode extends Model
{
    use FlushesCacheAfterCommit;

    protected $fillable = ['type', 'content', 'status'];

    protected $casts = [
        'type'   => CustomCodeType::class,
        'status' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOfType($query, CustomCodeType $type)
    {
        return $query->where('type', $type);
    }

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    public static function getCached(CustomCodeType $type): ?self
    {
        return Cache::rememberForever(self::cacheKey($type), function () use ($type) {
            return self::active()->ofType($type)->first();
        });
    }

    public function flushCache(): void
    {
        $key = self::cacheKey($this->type);

        static::flushCacheAfterCommit(static function () use ($key): void {
            Cache::forget($key);
        });
    }

    public static function cacheKey(CustomCodeType $type): string
    {
        return 'custom_code_'.$type->value;
    }

    /*
    |--------------------------------------------------------------------------
    | Model Booting
    |--------------------------------------------------------------------------
    */

    protected static function booted(): void
    {
        static::saved(fn (self $customCode) => $customCode->flushCache());
    }
}
