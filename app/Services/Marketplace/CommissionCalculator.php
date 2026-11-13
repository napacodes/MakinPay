<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceSetting;
use App\Models\Vendor;
use App\Support\DecimalMoney;
use App\Support\FeeCalculator;

class CommissionCalculator
{
    /**
     * Pure commission math: percentage of gross plus an optional fixed fee,
     * capped so commission can never exceed the gross amount.
     *
     * @return array{commission: float, vendor_net: float, pct: float, fixed: float}
     */
    public function compute(float $gross, float $pct, float $fixed = 0.0): array
    {
        // Exact decimals through the shared calculator, which already caps a
        // fee at the principal — the same clamp this used to apply by hand.
        $commission = app(FeeCalculator::class)->total($gross, $fixed, $pct);
        $vendorNet  = (float) DecimalMoney::subtract($gross, $commission, DecimalMoney::CALCULATION_SCALE);

        return [
            'commission' => $commission,
            'vendor_net' => $vendorNet,
            'pct'        => $pct,
            'fixed'      => $fixed,
        ];
    }

    /**
     * Resolve the effective rates for a vendor (per-vendor override falls back
     * to the global marketplace setting) and compute the split.
     *
     * @return array{commission: float, vendor_net: float, pct: float, fixed: float}
     */
    public function forVendor(Vendor $vendor, float $gross): array
    {
        $settings = MarketplaceSetting::current();

        $pct = $vendor->commission_pct_override !== null
            ? (float) $vendor->commission_pct_override
            : (float) ($settings?->commission_pct ?? 0);

        $fixed = (float) ($settings?->commission_fixed ?? 0);

        return $this->compute($gross, $pct, $fixed);
    }
}
