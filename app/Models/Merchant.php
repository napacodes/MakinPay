<?php

namespace App\Models;

use App\Enums\EnvironmentMode;
use App\Enums\MerchantStatus;
use App\Services\PaymentLinkService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Merchant extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'business_name',
        'site_url',
        'currency_id',
        'business_logo',
        'business_description',
        'business_email',
        'fee',
        'fee_fixed',
        'fee_min',
        'fee_max',
        'status',
        'rejection_reason',
        'merchant_key',
        'api_key',
        'api_secret',
        'test_api_key',
        'test_api_secret',
        'test_merchant_key',
        'current_mode',
        'sandbox_enabled',
        'webhook_url',
    ];

    protected $hidden = ['api_key', 'api_secret', 'test_api_key', 'test_api_secret'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'fee'             => 'double',
        'status'          => MerchantStatus::class,
        'sandbox_enabled' => 'boolean',
        'current_mode'    => EnvironmentMode::class,
    ];

    /**
     * Determine if the merchant is approved and active.
     */
    public function isApproved(): bool
    {
        return $this->status === MerchantStatus::APPROVED;
    }

    /**
     * A shop still in review may try charges out in sandbox, as its API
     * keys already may: nothing moves real money there. Production waits for
     * approval; a refused or turned-off shop tries nothing.
     */
    public function canTrySandbox(): bool
    {
        return $this->status === MerchantStatus::PENDING && (bool) $this->sandbox_enabled;
    }

    /**
     * Actions are locked when merchant is disabled or rejected.
     */
    public function isActionLocked(): bool
    {
        return in_array($this->status, [MerchantStatus::DISABLED, MerchantStatus::REJECTED], true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MerchantStatus::APPROVED);
    }

    /**
     * Check if merchant is in sandbox/test mode
     */
    public function isSandboxMode(): bool
    {
        return $this->current_mode === EnvironmentMode::SANDBOX;
    }

    /**
     * Check if merchant is in production mode
     */
    public function isProductionMode(): bool
    {
        return $this->current_mode === EnvironmentMode::PRODUCTION;
    }

    /**
     * Get current API credentials based on active mode
     */
    public function getCurrentApiKey(): ?string
    {
        return $this->isSandboxMode() ? $this->test_api_key : $this->api_key;
    }

    /**
     * Get current API secret based on active mode
     */
    public function getCurrentApiSecret(): ?string
    {
        return $this->isSandboxMode() ? $this->test_api_secret : $this->api_secret;
    }

    /**
     * Get current merchant key based on active mode
     */
    public function getCurrentMerchantKey(): ?string
    {
        return $this->isSandboxMode() ? $this->test_merchant_key : $this->merchant_key;
    }

    /**
     * Switch to sandbox mode
     */
    public function switchToSandbox(): bool
    {
        if (! $this->sandbox_enabled) {
            return false;
        }

        $this->current_mode = EnvironmentMode::SANDBOX;

        return $this->save();
    }

    /**
     * Switch to production mode
     */
    public function switchToProduction(): bool
    {
        $this->current_mode = EnvironmentMode::PRODUCTION;

        return $this->save();
    }

    /**
     * Check if test credentials are set
     */
    public function hasTestCredentials(): bool
    {
        return ! empty($this->test_api_key) && ! empty($this->test_api_secret) && ! empty($this->test_merchant_key);
    }

    /**
     * Generate test credentials if not exists
     */
    public function generateTestCredentials(): void
    {
        if (! $this->hasTestCredentials()) {
            $this->test_api_key      = 'test_'.Str::random(40);
            $this->test_api_secret   = 'test_secret_'.Str::random(32);
            $this->test_merchant_key = 'test_merchant_'.Str::random(16);
            $this->save();
        }
    }

    /**
     * What this merchant's fee shape actually charges, at a few amounts.
     *
     * An admin setting a percentage, a fixed component, a floor and a ceiling
     * has no way to see what the combination does — which is how a ceiling ends
     * up binding on almost every real payment without anyone noticing. This
     * asks the calculator, twice: once with the bounds and once without, so it
     * can say which bound bound rather than guessing from the numbers.
     *
     * Delegates to the one place a merchant fee is computed, so the preview
     * cannot drift from what is charged. Display only — nothing settles from it.
     *
     * @param  list<float>                                            $amounts
     * @return list<array{amount: float, fee: float, bound: ?string}>
     */
    public function feePreview(array $amounts = [10.0, 500.0, 5000.0]): array
    {
        $currencyCode = $this->currency?->code;

        return array_map(function (float $amount) use ($currencyCode): array {
            [$fee] = PaymentLinkService::merchantFee(
                $amount, $this->fee, $this->fee_fixed, $this->fee_min, $this->fee_max, $currencyCode
            );

            [$unbounded] = PaymentLinkService::merchantFee(
                $amount, $this->fee, $this->fee_fixed, null, null, $currencyCode
            );

            $bound = null;

            if ($this->fee_min !== null && $unbounded < (float) $this->fee_min) {
                $bound = 'floor';
            } elseif ($this->fee_max !== null && $unbounded > (float) $this->fee_max) {
                $bound = 'cap';
            }

            return ['amount' => $amount, 'fee' => $fee, 'bound' => $bound];
        }, $amounts);
    }

    public function scopeFilter($query, Request $request)
    {
        /*
         * Both of these filter on status — `type` is the older spelling, kept
         * because existing links still use it. An unrecognised value used to
         * reach the where clause unchecked, so a typo or a stale bookmark
         * returned an empty table that read as "no merchants" rather than
         * "no such status".
         */
        $statuses = MerchantStatus::all();

        $query->when(
            $request->filled('type') && in_array($request->type, $statuses, true),
            fn ($q) => $q->where('status', $request->type)
        );

        $query->when(
            $request->filled('status') && in_array($request->status, $statuses, true),
            fn ($q) => $q->where('status', $request->status)
        );

        $query->when($request->filled('daterange'), function ($q) use ($request) {
            $dateRange = explode(',', $request->daterange);

            if (count($dateRange) === 2) {
                [$startDate, $endDate] = $dateRange;

                $q->whereBetween('created_at', [
                    Carbon::parse(trim($startDate))->startOfDay(),
                    Carbon::parse(trim($endDate))->endOfDay(),
                ]);
            }
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;
            $q->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('merchant_key', 'like', "%{$search}%")
                    ->orWhere('business_email', 'like', "%{$search}%")
                    ->orWhere('site_url', 'like', "%{$search}%");
            });
        });

        return $query;
    }

    public function getBusinessLogoAttribute(?string $value): string
    {
        return $value ?? '/general/static/default/shop.png';
    }

    /**
     * Get the API Key only when the merchant is APPROVED.
     */
    public function getApiKeyAttribute(?string $value): string
    {
        return $this->status === MerchantStatus::APPROVED
            ? ($value ?? __('No API Key Generated'))
            : __('Your API Key will be available after approval.');
    }

    /**
     * Get the API Secret only when the merchant is APPROVED.
     */
    public function getApiSecretAttribute(?string $value): string
    {
        return $this->status === MerchantStatus::APPROVED
            ? ($value ?? __('No API Secret Generated'))
            : __('Your API Secret will be available after approval.');
    }

    /**
     * Get the user that owns the merchant.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the currency associated with the merchant.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function supportedCurrencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class, 'merchant_currencies')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(DepositMethod::class, 'merchant_deposit_methods')
            ->withTimestamps();
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(MerchantWebhookDelivery::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class);
    }

    /**
     * @return array<int, int>
     */
    public function supportedCurrencyIds(): array
    {
        $ids = $this->relationLoaded('supportedCurrencies')
            ? $this->supportedCurrencies->pluck('id')
            : $this->supportedCurrencies()->pluck('currencies.id');

        if ($ids->isEmpty() && $this->currency_id !== null) {
            $ids = collect([(int) $this->currency_id]);
        }

        return $ids
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function supportsCurrency(int $currencyId): bool
    {
        return in_array($currencyId, $this->supportedCurrencyIds(), true);
    }

    public function currencyForCode(string $currencyCode): ?Currency
    {
        $code = strtoupper(trim($currencyCode));

        $currency = Currency::query()
            ->where('code', $code)
            ->first();

        if (! $currency || ! $this->supportsCurrency((int) $currency->id)) {
            return null;
        }

        return $currency;
    }

    public function primaryCurrency(): ?Currency
    {
        if ($this->relationLoaded('supportedCurrencies')) {
            return $this->supportedCurrencies->first(fn (Currency $currency): bool => (bool) $currency->pivot?->is_primary) ?? $this->currency;
        }

        return $this->supportedCurrencies()
            ->wherePivot('is_primary', true)
            ->first() ?? $this->currency;
    }
}
