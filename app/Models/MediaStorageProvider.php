<?php

namespace App\Models;

use App\Support\ByteSize;
use Database\Factories\MediaStorageProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MediaStorageProvider extends Model
{
    /** @use HasFactory<MediaStorageProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'visibility',
        'prefix',
        'bucket',
        'region',
        'endpoint',
        'url',
        'root',
        'access_key',
        'secret_key',
        'use_path_style_endpoint',
        'is_active',
        'throw',
        'options',
        'quota_bytes',
        'last_checked_at',
        'last_check_status',
        'last_check_message',
        'created_by',
        'updated_by',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Bytes this provider is holding.
     *
     * Summed rather than asked of the provider: an S3-compatible bucket has no
     * cheap "how big are you" call, and the rail redraws on every page load.
     * Held for five minutes, and dropped outright whenever the library gains
     * or loses a file so the number never contradicts the grid beside it.
     */
    public function usedBytes(): int
    {
        return (int) Cache::remember(
            self::usageCacheKey($this->id),
            now()->addMinutes(5),
            fn (): int => (int) $this->assets()->sum('size'),
        );
    }

    public function usedHuman(): string
    {
        return ByteSize::human($this->usedBytes(), 1);
    }

    public function quotaHuman(): ?string
    {
        return $this->quota_bytes === null ? null : ByteSize::human((int) $this->quota_bytes, 1);
    }

    /**
     * Share of the allowance in use, 0-100. Null when no allowance is set —
     * a bar with no ceiling would have to invent one.
     */
    public function usagePercent(): ?float
    {
        $quota = (int) $this->quota_bytes;

        if ($quota <= 0) {
            return null;
        }

        return round(min(100, ($this->usedBytes() / $quota) * 100), 1);
    }

    public static function forgetUsageCache(int|string|null $providerId): void
    {
        if ($providerId !== null) {
            Cache::forget(self::usageCacheKey($providerId));
        }
    }

    private static function usageCacheKey(int|string|null $providerId): string
    {
        return 'media-storage-provider-usage:'.$providerId;
    }

    public function filesystemConfig(): array
    {
        if ($this->driver === 's3') {
            return array_filter([
                'driver'                  => 's3',
                'key'                     => $this->access_key,
                'secret'                  => $this->secret_key,
                'region'                  => $this->region,
                'bucket'                  => $this->bucket,
                'url'                     => $this->url,
                'endpoint'                => $this->endpoint,
                'use_path_style_endpoint' => $this->use_path_style_endpoint,
                'visibility'              => $this->visibility,
                'throw'                   => $this->throw,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return [
            'driver'     => 'local',
            'root'       => $this->root ?: storage_path('app/public'),
            'url'        => $this->url ?: asset('storage'),
            'visibility' => $this->visibility,
            'throw'      => $this->throw,
        ];
    }

    public function resolvedPrefix(): string
    {
        $prefix = trim((string) $this->prefix, '/');

        return $prefix !== '' ? Str::of($prefix)->replace('\\', '/')->trim('/')->toString() : 'media';
    }

    public function isLocal(): bool
    {
        return $this->driver === 'local';
    }

    public function isS3Compatible(): bool
    {
        return $this->driver === 's3';
    }

    protected function casts(): array
    {
        return [
            'access_key'              => 'encrypted',
            'secret_key'              => 'encrypted',
            'use_path_style_endpoint' => 'boolean',
            'is_active'               => 'boolean',
            'throw'                   => 'boolean',
            'options'                 => 'array',
            'quota_bytes'             => 'integer',
            'last_checked_at'         => 'datetime',
        ];
    }
}
