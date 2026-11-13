<?php

namespace App\Models;

use App\Enums\CustomerPhoneMode;
use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Support\CustomerPhone;
use App\Traits\Models\ResolvesEffectiveLimits;
use App\Traits\Models\ResolvesRoleBasedCharges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class DepositMethod extends Model
{
    use HasFactory;
    use ResolvesEffectiveLimits;
    use ResolvesRoleBasedCharges;

    protected $fillable = [
        'payment_gateway_id',
        'logo',
        'name',
        'type',
        'method_code',
        'currency',
        'currency_symbol',
        'min_deposit',
        'max_deposit',
        'conversion_rate_live',
        'conversion_rate',
        'conversion_rate_updated_at',
        'provider_min_amount',
        'provider_max_amount',
        'charge_type',
        'charge',
        'user_charge',
        'user_charge_type',
        'merchant_charge',
        'merchant_charge_type',
        'agent_charge',
        'agent_charge_type',
        'charge_fixed',
        'charge_percent',
        'charge_min',
        'charge_max',
        'user_charge_fixed',
        'user_charge_percent',
        'user_charge_min',
        'user_charge_max',
        'merchant_charge_fixed',
        'merchant_charge_percent',
        'merchant_charge_min',
        'merchant_charge_max',
        'agent_charge_fixed',
        'agent_charge_percent',
        'agent_charge_min',
        'agent_charge_max',
        'fields',
        'receive_payment_details',
        'status',
    ];

    protected $casts = [
        'type'                 => MethodType::class,
        'user_charge_type'     => FixPctType::class,
        'merchant_charge_type' => FixPctType::class,
        'agent_charge_type'    => FixPctType::class,
        'payment_gateway_id'   => 'integer',
        'status'               => 'boolean',
        'min_deposit'          => 'float',
        'max_deposit'          => 'float',
        'conversion_rate_live' => 'boolean',
        // No cast for `conversion_rate`: getConversionRateAttribute() takes
        // precedence over a cast anyway, and it returns a rate-scale string on
        // purpose. Declaring 'float' here only advertised the precision this
        // column no longer loses.
        'charge'          => 'float',
        'user_charge'     => 'double',
        'merchant_charge' => 'double',
        'agent_charge'    => 'double',
    ];

    /**
     * @return array<int|string, mixed>
     */
    public function getFieldsAttribute(mixed $value): array
    {
        return $this->normalizeFields($value);
    }

    public function setFieldsAttribute(mixed $value): void
    {
        $this->attributes['fields'] = json_encode($this->normalizeFields($value));
    }

    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * Whether a checkout for this method asks the payer for their phone.
     *
     * Read through the cached gateway row unless the relation is already
     * loaded, because every checkout lists several methods and asks this of
     * each one.
     */
    public function customerPhoneMode(): CustomerPhoneMode
    {
        if ($this->type !== MethodType::AUTOMATIC || ! $this->payment_gateway_id) {
            return CustomerPhoneMode::None;
        }

        $gateway = $this->relationLoaded('paymentGateway')
            ? $this->paymentGateway
            : PaymentGateway::getById((int) $this->payment_gateway_id);

        return CustomerPhone::modeForGateway($gateway?->code);
    }

    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'merchant_deposit_methods')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeGetByCode($query, $code)
    {
        return $query->where('method_code', $code)->first();
    }

    public static function getByCode($code)
    {
        return Cache::rememberForever('deposit_method_'.$code, function () use ($code) {
            return self::where('method_code', $code)->first();
        });
    }

    public function getLogoAltAttribute(): string
    {
        if ($this->attributes['type'] !== MethodType::AUTOMATIC->value) {
            return $this->attributes['logo'] ?? '';
        }

        return $this->attributes['logo'] ?? $this->paymentGateway->logo;
    }

    /**
     * The rate this method converts a base-currency amount at.
     *
     * Reads the stored column. `conversion_rate_live` used to make this fetch
     * from the rate provider on the spot, which put a blocking HTTP call inside
     * an attribute read — the deposit form prices every active method, so a
     * page could open a dozen of them, and a provider timeout became a failed
     * page rather than a stale number.
     *
     * `currency:refresh-rates` keeps live methods current on a schedule.
     */
    public function getConversionRateAttribute(): string
    {
        return (string) ($this->attributes['conversion_rate'] ?? '0');
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeFields(mixed $value): array
    {
        while (is_string($value)) {
            $decoded = json_decode(trim($value), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Flush the cache when a deposit method is deleted.
        static::deleted(function ($model) {
            Cache::forget('deposit_method_'.$model->method_code);
        });

        // Flush the cache when a deposit method is updated.
        static::updated(function ($model) {
            Cache::forget('deposit_method_'.$model->method_code);
        });

        // Flush the cache when a deposit method is created.
        static::created(function ($model) {
            Cache::forget('deposit_method_'.$model->method_code);
        });
    }

    protected function platformMinColumn(): string
    {
        return 'min_deposit';
    }

    protected function platformMaxColumn(): string
    {
        return 'max_deposit';
    }
}
