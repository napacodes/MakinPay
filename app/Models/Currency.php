<?php

namespace App\Models;

use App\Constants\CurrencyType;
use App\Services\CurrencyService;
use App\Support\CurrencyPrecision;
use App\Support\RateGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'flag',
        'name',
        'code',
        'symbol',
        'symbol_position',
        'number_grouping',
        'type',
        'decimal_places',
        'exchange_rate',
        'rate_updated_at',
        'rate_source',
        'rate_live',
        'auto_wallet',
        'default',
        'status',
        'fee',
    ];

    protected $casts = [
        'rate_live'       => 'boolean',
        'decimal_places'  => 'integer',
        'exchange_rate'   => 'decimal:18',
        'rate_updated_at' => 'datetime',
        'default'         => 'boolean',
        'status'          => 'boolean',
    ];

    /**
     * Get the default currency.
     */
    public static function getDefault(): ?Currency
    {
        return static::where('default', true)->first();
    }

    public function isDefault(): bool
    {
        return static::where('default', true)->exists();
    }

    /**
     * Whether an amount in this currency can currently be converted.
     *
     * The server already refuses to convert at an unusable rate, but a page
     * that still offers the wallet lets a customer watch a preview built from
     * that rate — a placeholder of 1 renders "1 BTC = 1 USD" right up until
     * they submit. Views ask this so the wallet is never offered in the first
     * place, and the nonsense figure is never drawn.
     */
    public function isConvertible(): bool
    {
        return app(RateGuard::class)->passes($this);
    }

    /**
     * Why this currency cannot be converted, or null when it can.
     */
    public function conversionBlockReason(): ?string
    {
        return app(RateGuard::class)->reject($this);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * Currencies that should be provisioned automatically for a user.
     *
     * The operational status is the platform-wide master switch. Grouping the
     * auto/default conditions prevents an inactive default/auto currency from
     * escaping the status constraint through an unscoped `orWhere`.
     *
     * @return Collection<int, Currency>
     */
    public static function autoWallets(): Collection
    {
        return static::query()
            ->active()
            ->where(function (Builder $query): void {
                $query->where('auto_wallet', true)
                    ->orWhere('default', true);
            })
            ->get();
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * Savings vaults kept in this currency - a vault has no currency of its
     * own, it is its wallet's.
     */
    public function vaults(): HasManyThrough
    {
        return $this->hasManyThrough(Vault::class, Wallet::class, 'currency_id', 'wallet_id');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_currencies')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'merchant_currencies')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(CurrencyRole::class);
    }

    public function activeRoles(): HasMany
    {
        return $this->hasMany(CurrencyRole::class)->where('is_active', true);
    }

    public function hasRole(string $roleName): bool
    {
        return $this->activeRoles->pluck('role_name')->contains($roleName);
    }

    /**
     * Role details for the browser.
     *
     * This is the single payload every frontend fee preview reads, so the
     * fixed/percent components and the currency's own precision are exposed
     * here rather than being re-derived per screen. `fee_type` and `fee` stay
     * for one release so an un-migrated script keeps working.
     *
     * @return array<string, mixed>
     */
    public function getRoleInfo($roleName)
    {
        $role = $this->activeRoles->where('role_name', $roleName)->first();

        if ($role === null) {
            return [];
        }

        return $role->only(['role_name', 'min_limit', 'max_limit', 'fee_type', 'fee']) + [
            'fee_fixed'   => (string) $role->fee_fixed,
            'fee_percent' => (string) $role->fee_percent,
            'fee_min'     => $role->fee_min,
            'fee_max'     => $role->fee_max,
            'decimals'    => currencyDecimals($this->code),
            'code'        => $this->code,
            'symbol'      => $this->symbol,
        ];
    }

    /**
     * The rate at which 1 unit of the site base currency buys this currency.
     *
     * This reads the stored column and nothing else. It used to fetch a live
     * rate here when `rate_live` was set, which made reading an attribute
     * perform a blocking third-party HTTP call — one per currency, on whichever
     * request happened to be first past the cache. Worse, the two readers of a
     * rate disagreed: RateGuard inspected the stored value while every
     * conversion went through this accessor, so a currency the guard had
     * cleared could still be converted at something else entirely.
     *
     * `currency:refresh-rates` now writes live rates into the column on a
     * schedule, so there is one number, it is already fresh, and reading it
     * costs a property access. RateGuard's staleness check is what notices a
     * feed that has stopped.
     *
     * Returned as a raw string rather than through the `decimal:18` cast so a
     * rate keeps every digit it was stored with.
     */
    public function getExchangeRateAttribute(): string
    {
        // Tolerate partial hydration: a query that selects only exchange_rate
        // (Builder::value(), a narrowed get()) leaves the rest absent, and
        // reading them directly used to throw.
        return (string) ($this->attributes['exchange_rate'] ?? '0');
    }

    protected static function boot()
    {
        parent::boot();

        // Derive a sensible precision whenever one is not supplied. The admin
        // form already defaults it, but currencies are also created by the
        // installer, seeders and customer code — and a crypto currency left at
        // the column default of 2 renders every balance as "0.00", which is
        // the original bug reappearing for every newly added currency.
        static::creating(function (Currency $currency): void {
            if (($currency->attributes['decimal_places'] ?? null) === null) {
                $currency->decimal_places = CurrencyPrecision::for(
                    $currency->code,
                    $currency->type ?? CurrencyType::FIAT,
                );
            }
        });

        // Stamp when the rate was last set, so a rate that was never
        // configured can be told apart from one that is simply equal to 1.
        // Only an actual change counts — re-saving an unrelated field must not
        // make a months-old rate look fresh.
        static::saving(function (Currency $currency): void {
            if (! $currency->isDirty('exchange_rate')) {
                return;
            }

            if (! $currency->isDirty('rate_updated_at')) {
                $currency->rate_updated_at = now();
            }

            if (! $currency->isDirty('rate_source')) {
                $currency->rate_source = $currency->rate_live ? 'live' : 'manual';
            }
        });

        // Clear currency cache after saving a currency. The original code is
        // passed too so a renamed currency does not leave a stale entry behind.
        static::saved(function (Currency $currency) {
            app(CurrencyService::class)->clearCurrencyCache(
                (string) $currency->code,
                (string) $currency->getOriginal('code', $currency->code),
            );
        });

        // Clear currency cache after deleting a currency. Its code is passed
        // explicitly because the row is gone and a table scan cannot reach it.
        static::deleted(function (Currency $currency) {
            app(CurrencyService::class)->clearCurrencyCache((string) $currency->code);
        });
    }
}
