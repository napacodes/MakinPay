<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\Models\FlushesCacheAfterCommit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Language extends Model
{
    use FlushesCacheAfterCommit, HasFactory;

    protected $fillable = [
        'flag',
        'name',
        'native_name',
        'code',
        'is_default',
        'is_rtl',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_rtl'     => 'boolean',
        'status'     => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Boot the model and listen for model events.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    /**
     * What to call this language on screen.
     *
     * The endonym when there is one, because a language picker is read by
     * people who cannot read the page they are on: "árabe" in a Spanish list
     * is a word an Arabic speaker cannot use, and "العربية" is one they
     * recognise without reading anything else.
     *
     * Falls back to the English name, so a row that predates the column — or
     * one whose administrator left the field blank — renders exactly as it
     * did before.
     */
    public function displayName(): string
    {
        $native = trim((string) ($this->native_name ?? ''));

        return $native !== '' ? $native : (string) $this->name;
    }

    /**
     * Get the default language (cached).
     */
    public static function default(): ?self
    {
        if (! Schema::hasTable('languages')) {
            return null;
        }

        return Cache::rememberForever('default_language', function () {
            return self::where('is_default', true)->first(['code', 'is_rtl']);
        });
    }

    /**
     * Resolve the text direction ("ltr" or "rtl") for the active locale.
     */
    public static function currentDirection(): string
    {
        return self::directionForLocale(app()->getLocale());
    }

    /**
     * Resolve the text direction ("ltr" or "rtl") for a given language code.
     */
    public static function directionForLocale(string $code): string
    {
        return in_array($code, self::rtlCodes(), true) ? 'rtl' : 'ltr';
    }

    /**
     * Get the codes of every right-to-left language (cached).
     *
     * @return array<int, string>
     */
    public static function rtlCodes(): array
    {
        if (! Schema::hasTable('languages')) {
            return [];
        }

        return Cache::rememberForever('languageRtlCodes', function () {
            return self::where('is_rtl', true)->pluck('code')->all();
        });
    }

    /**
     * Get all active languages with name and code only (cached).
     */
    public static function languageGet(): Collection
    {
        if (! Schema::hasTable('languages')) {
            return collect();
        }

        return Cache::rememberForever('languagesPluck', function () {
            return self::where('status', Status::ACTIVE)->pluck('name', 'code');
        });
    }

    /**
     * Get all active languages with full fields (cached).
     */
    public static function activeCached(): Collection
    {
        if (! Schema::hasTable('languages')) {
            return collect();
        }

        return Cache::rememberForever('languages', function () {
            return self::where('status', true)->get();
        });
    }

    /**
     * Clear all cached language data.
     */
    public static function flushCache(): void
    {
        static::flushCacheAfterCommit(static function (): void {
            Cache::forget('default_language');
            Cache::forget('languagesPluck');
            Cache::forget('languages');
            Cache::forget('languageRtlCodes');
        });
    }
}
