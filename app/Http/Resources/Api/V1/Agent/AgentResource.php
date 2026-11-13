<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agent profile, as its own operator sees it.
 *
 * qr_token is excluded. It is the secret in the URL a customer scans to be
 * cashed out, so it is a bearer credential for this agent's till - the QR
 * endpoint renders it into an image, and nothing else hands it over as text.
 *
 * @property-read Agent $resource
 */
class AgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agent = $this->resource;

        return [
            'id'          => $agent->id,
            'code'        => $agent->agent_code,
            'name'        => $agent->agent_name,
            'description' => $agent->description,
            'logo'        => $agent->logo,
            'status'      => $agent->status?->value,
            'is_approved' => $agent->isApproved(),

            // The reason an administrator gave, so the app can show it rather
            // than leaving a rejected agent staring at a dead screen.
            'rejection_reason' => $agent->rejection_reason,

            'commission_percent' => (string) $agent->commission,
            'qr_enabled'         => (bool) $agent->qr_enabled,

            'currency' => $agent->relationLoaded('currency') && $agent->currency !== null ? [
                'code'   => $agent->currency->code,
                'symbol' => $agent->currency->symbol,
            ] : null,

            'supported_currencies' => $agent->relationLoaded('supportedCurrencies')
                ? $agent->supportedCurrencies->pluck('code')->values()
                : null,

            'created_at' => $agent->created_at?->toIso8601String(),
        ];
    }
}
