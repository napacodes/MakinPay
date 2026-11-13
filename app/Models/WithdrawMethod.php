<?php

namespace App\Models;

use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Support\DecimalMoney;
use App\Traits\Models\ResolvesEffectiveLimits;
use App\Traits\Models\ResolvesRoleBasedCharges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WithdrawMethod extends Model
{
    use ResolvesEffectiveLimits;
    use ResolvesRoleBasedCharges;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'withdraw_methods';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'payment_gateway_id',
        'logo',
        'name',
        'type',
        'method_code',
        'currency',
        'currency_symbol',
        'min_withdraw',
        'max_withdraw',
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
        'process_time_value',
        'process_time_unit',
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
        'status',
        'mass_payout_driver',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'type'                 => MethodType::class,
        'user_charge_type'     => FixPctType::class,
        'merchant_charge_type' => FixPctType::class,
        'agent_charge_type'    => FixPctType::class,
        'min_withdraw'         => 'double',
        'max_withdraw'         => 'double',
        'rate'                 => 'double',
        'charge'               => 'double',
        'user_charge'          => 'double',
        'merchant_charge'      => 'double',
        'agent_charge'         => 'double',
        'process_time_value'   => 'integer',
        'fields'               => 'array',
        'status'               => 'boolean',
    ];

    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * Scope a query to only include active withdrawal methods.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * The rate this method converts a base-currency amount at.
     *
     * Reads the stored column. `conversion_rate_live` used to make this fetch
     * from the rate provider on the spot, which put a blocking HTTP call inside
     * an attribute read. `currency:refresh-rates` keeps live methods current on
     * a schedule instead.
     */
    public function getConversionRateAttribute(): string
    {
        return (string) ($this->attributes['conversion_rate'] ?? '0');
    }

    /**
     * Get the full processing time as a string.
     */
    public function getProcessingTimeAttribute(): string
    {
        return $this->process_time_value ? "{$this->process_time_value} {$this->process_time_unit}" : __('Automated ASAP');
    }

    /**
     * Check if a given amount is within the allowed limits.
     */
    public function isWithinLimits(string|int|float $amount): bool
    {
        // Compared as decimals, so a figure with more digits than a double
        // holds is judged on all of them (CR1). A missing limit keeps the
        // meaning the float comparison gave it: no minimum is no floor, and
        // no maximum lets nothing through (`$amount <= null` was false).
        return ($this->min_withdraw === null || DecimalMoney::compare($amount, (string) $this->min_withdraw) >= 0)
            && ($this->max_withdraw !== null && DecimalMoney::compare($amount, (string) $this->max_withdraw) <= 0);
    }

    public function getLogoAltAttribute(): string
    {
        if ($this->attributes['type'] !== MethodType::AUTOMATIC->value) {
            return $this->attributes['logo'] ?? '';
        }

        return $this->attributes['logo'] ?? $this->paymentGateway->logo;
    }

    protected function platformMinColumn(): string
    {
        return 'min_withdraw';
    }

    protected function platformMaxColumn(): string
    {
        return 'max_withdraw';
    }
}
