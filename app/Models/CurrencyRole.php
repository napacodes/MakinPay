<?php

namespace App\Models;

use App\Support\FeeCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurrencyRole extends Model
{
    use HasFactory;

    public $casts = [
        'is_active'   => 'boolean',
        'min_limit'   => 'float',
        'max_limit'   => 'float',
        'fee'         => 'float',
        'fee_fixed'   => 'decimal:8',
        'fee_percent' => 'decimal:8',
        'fee_min'     => 'decimal:8',
        'fee_max'     => 'decimal:8',
    ];

    protected $fillable = [
        'currency_id',
        'role_name',
        'fee_fixed',
        'fee_percent',
        'fee_min',
        'fee_max',
        'min_limit',
        'max_limit',
        'fee_type',
        'fee',
        'is_active',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Keep the legacy `fee` / `fee_type` pair and the fixed/percent pair in
     * sync, in whichever direction was written.
     *
     * The legacy columns stay populated so customer customisations and API
     * consumers reading `fee_type` keep working, and a legacy-shaped write
     * still lands in the new columns the calculator reads.
     */
    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            // `fee_fixed` and `fee_percent` are NOT NULL DEFAULT 0, but a blank
            // form field arrives as null: ConvertEmptyStringsToNull rewrites ''
            // before validation, and `nullable` lets it through. An
            // unconfigured component means zero, so settle it here rather than
            // letting the write fail on a constraint. `fee_min` / `fee_max`
            // stay nullable — "no floor" is not a floor of zero.
            foreach (['fee_fixed', 'fee_percent'] as $component) {
                if (array_key_exists($component, $role->getAttributes()) && $role->{$component} === null) {
                    $role->{$component} = 0;
                }
            }

            if ($role->isDirty(['fee_fixed', 'fee_percent'])) {
                $role->fee_type = FeeCalculator::toLegacyType($role->fee_fixed, $role->fee_percent);
                $role->fee      = (float) $role->fee_fixed > 0 ? (float) $role->fee_fixed : (float) $role->fee_percent;

                return;
            }

            if ($role->isDirty(['fee', 'fee_type'])) {
                $split = FeeCalculator::fromLegacy($role->fee_type, $role->fee);

                $role->fee_fixed   = $split['fixed'];
                $role->fee_percent = $split['percent'];
            }
        });
    }
}
