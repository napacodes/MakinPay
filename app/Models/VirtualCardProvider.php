<?php

namespace App\Models;

use App\Support\DecimalMoney;
use App\Support\FeeCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class VirtualCardProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'logo',
        'description',
        'supported_networks',
        'supported_currencies',
        'supported_countries',
        'issue_fee',
        'issue_fee_pct',
        'min_balance',
        'status',
        'config',
        'capabilities',
        'payment_gateway_id',
        'order',
    ];

    protected $casts = [
        'supported_networks'   => 'array',
        'supported_currencies' => 'array',
        'supported_countries'  => 'array',
        'issue_fee'            => 'float',
        'issue_fee_pct'        => 'float',
        'min_balance'          => 'float',
        'status'               => 'boolean',
        'config'               => 'array',
        'capabilities'         => 'array',
    ];

    // Relationships
    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function scopeForNetwork(Builder $query, string $network): Builder
    {
        return $query->whereJsonContains('supported_networks', $network);
    }

    public function scopeForCurrency(Builder $query, string $currency): Builder
    {
        return $query->whereJsonContains('supported_currencies', $currency);
    }

    /**
     * Scope: providers that support issuance for the given ISO-2 country
     * code. Providers with NULL `supported_countries` are treated as
     * unrestricted ("issue anywhere") so legacy rows keep working.
     */
    public function scopeForCountry(Builder $query, ?string $country): Builder
    {
        if (! $country) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($country): void {
            $q->whereNull('supported_countries')
                ->orWhereJsonContains('supported_countries', strtoupper($country));
        });
    }

    // Accessors
    public function getFeeFormattedAttribute(): string
    {
        // The issue fee is denominated in the base currency, so it takes the
        // base currency's precision rather than a hardcoded two — which is
        // wrong the moment an install's base is JPY, KWD or a stablecoin.
        return money($this->issue_fee);
    }

    public function getLogoUrlAttribute(): string
    {
        return $this->logo ? asset($this->logo) : asset('general/static/default/payment-gateway.png');
    }

    public function getNetworksListAttribute(): string
    {
        return is_array($this->supported_networks) ? implode(', ', array_map('ucfirst', $this->supported_networks)) : '';
    }

    public function getCurrenciesListAttribute(): string
    {
        return is_array($this->supported_currencies) ? implode(', ', $this->supported_currencies) : '';
    }

    // Calculations
    public function issueTotalFee(float $baseAmount = 0.0): float
    {
        // Exact decimals at calculation scale rather than the hardcoded 2:
        // a percentage of a crypto load amount rounded to cents vanished.
        // FeeCalculator caps the fee at the principal, which only applies once
        // there is a principal to cap against.
        return $baseAmount > 0
            ? app(FeeCalculator::class)->total($baseAmount, $this->issue_fee, $this->issue_fee_pct)
            : (float) DecimalMoney::round(max(0, (float) ($this->issue_fee ?? 0)), DecimalMoney::CALCULATION_SCALE);
    }

    /**
     * Resolve the merged capability map for this provider.
     *
     * Order: DB column → config/virtual_card.php override → defaults.
     * Adding a new capability key only needs an entry in default_capabilities.
     */
    public function getResolvedCapabilitiesAttribute(): array
    {
        $defaults = (array) config('virtual_card.default_capabilities', []);
        $override = (array) (config("virtual_card.capabilities.{$this->code}") ?? []);
        $stored   = is_array($this->capabilities) ? $this->capabilities : [];

        return array_merge($defaults, $override, $stored);
    }

    public function supports(string $capability): bool
    {
        return (bool) ($this->resolved_capabilities[$capability] ?? false);
    }

    /**
     * The currency this provider keeps its cards in, or null when a card is
     * denominated in whatever wallet funds it.
     *
     * The provider row's `config.card_currency` wins over the catalogue in
     * `config/virtual_card.php`, so an operator can pin it per installation.
     */
    public function cardCurrency(): ?string
    {
        $configured = is_array($this->config) ? ($this->config['card_currency'] ?? null) : null;
        $code       = strtoupper(trim((string) ($configured ?: config('virtual_card.card_currencies.'.$this->code))));

        return $code !== '' ? $code : null;
    }

    public function supportsNetwork(?string $network): bool
    {
        if (! $network) {
            return true;
        }

        $list = $this->supported_networks;
        if (! is_array($list) || empty($list)) {
            return true;
        }

        return in_array(strtolower($network), array_map('strtolower', $list), true);
    }

    public function supportsCurrency(?string $currency): bool
    {
        if (! $currency) {
            return true;
        }

        $list = $this->supported_currencies;
        if (! is_array($list) || empty($list)) {
            return true;
        }

        return in_array(strtoupper($currency), array_map('strtoupper', $list), true);
    }

    /**
     * Whether this provider can issue a card for a cardholder whose
     * billing address country is the given ISO-2 code. Empty / NULL
     * `supported_countries` means "no restriction".
     */
    public function supportsCountry(?string $country): bool
    {
        if (! $country) {
            return true;
        }

        $list = $this->supported_countries;
        if (! is_array($list) || empty($list)) {
            return true;
        }

        return in_array(strtoupper($country), array_map('strtoupper', $list), true);
    }

    // Caching (optional)
    public static function allCached()
    {
        return Cache::rememberForever('virtual_card_providers_all', function () {
            return self::active()->orderBy('order')->orderBy('id')->get();
        });
    }

    public static function flushCache(self $provider): void
    {
        Cache::forget('virtual_card_providers_all');
        Cache::forget("virtual_card_provider_code_{$provider->code}");
    }

    protected static function booted()
    {
        static::saved(fn (self $provider) => self::flushCache($provider));
        static::deleted(fn (self $provider) => self::flushCache($provider));
    }
}
