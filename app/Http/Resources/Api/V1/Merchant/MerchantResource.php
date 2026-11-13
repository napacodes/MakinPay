<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Merchant;

use App\Models\Merchant;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant profile, without any of its keys.
 *
 * Six columns on this row are credentials - api_key, api_secret,
 * merchant_key, and the sandbox trio - and the model exposes the first three
 * through accessors, so returning it raw would hand a phone the credentials
 * for the server-to-server API. Those belong on a server the merchant
 * controls; an app that holds them is an app that leaks them when it is
 * decompiled.
 *
 * The fee schedule IS included. A merchant needs to see what they are being
 * charged, and it is their own rate rather than a secret.
 *
 * @property-read Merchant $resource
 */
class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $merchant = $this->resource;
        $code     = $merchant->relationLoaded('currency') ? $merchant->currency?->code : null;

        return [
            'id'                   => $merchant->id,
            'business_name'        => $merchant->business_name,
            'business_email'       => $merchant->business_email,
            'business_description' => $merchant->business_description,
            'site_url'             => $merchant->site_url,
            // Absolute, like every avatar the API hands out, and null when
            // the shop never uploaded one: the app draws initials then,
            // rather than the web's grey placeholder shop.
            'logo' => $merchant->getRawOriginal('business_logo') === null ? null : asset($merchant->business_logo),

            'status'           => $merchant->status?->value,
            'is_approved'      => $merchant->isApproved(),
            'rejection_reason' => $merchant->rejection_reason,

            // Which environment the merchant's own integration is pointed at.
            // A mode is not a credential; the keys behind it are, and are not
            // here.
            'current_mode'    => $merchant->current_mode?->value ?? $merchant->current_mode,
            'sandbox_enabled' => (bool) $merchant->sandbox_enabled,
            // While in review: test charges, always in sandbox.
            'can_try_sandbox' => $merchant->canTrySandbox(),

            'fees' => [
                // Normalised so the app gets one predictable form. The column
                // is not a fixed-decimal cast, so 1.9 and 1.9000 both occur.
                'percent' => DecimalMoney::rate($merchant->fee ?? 0),
                'fixed'   => ApiMoney::amount($merchant->fee_fixed, $code),
                // Null when the shop has none: the fee is then unbounded,
                // which "0.00" would misstate as a cap of nothing.
                'minimum'  => $merchant->fee_min === null ? null : ApiMoney::amount($merchant->fee_min, $code),
                'maximum'  => $merchant->fee_max === null ? null : ApiMoney::amount($merchant->fee_max, $code),
                'currency' => $code,
                'decimals' => ApiMoney::decimals($code),
            ],

            'currency' => $code,

            'supported_currencies' => $merchant->relationLoaded('supportedCurrencies')
                ? $merchant->supportedCurrencies->pluck('code')->values()
                : null,

            'created_at' => $merchant->created_at?->toIso8601String(),
        ];
    }
}
