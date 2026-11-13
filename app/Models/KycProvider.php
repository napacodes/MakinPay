<?php

namespace App\Models;

use Database\Factories\KycProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycProvider extends Model
{
    /** @use HasFactory<KycProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'driver',
        'countries',
        'priority',
        'mode',
        'credentials',
        'status',
        'last_tested_at',
        'last_test_status',
    ];

    protected $hidden = [
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'countries'      => 'array',
            'credentials'    => 'encrypted:array',
            'priority'       => 'integer',
            'status'         => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Only enabled providers.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * Providers that cover the given ISO-3166 alpha-2 country (or the "*" wildcard).
     */
    public function scopeForCountry(Builder $query, string $countryCode): Builder
    {
        $countryCode = strtoupper($countryCode);

        return $query->where(function (Builder $q) use ($countryCode): void {
            $q->whereJsonContains('countries', $countryCode)
                ->orWhereJsonContains('countries', '*');
        });
    }

    /**
     * Read a single credential value.
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }

    public function isSandbox(): bool
    {
        return $this->mode !== 'live';
    }
}
