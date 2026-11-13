<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in device, as its owner is allowed to see it.
 *
 * Never the token. Sanctum stores only a hash of it and could not show the
 * original even if this wanted to, but the id is what a revoke needs and the
 * id is what is given.
 *
 * `is_current` is what makes the list actionable: without it a customer
 * signing a lost phone out has no way to tell which row is the phone in their
 * hand.
 *
 * @property-read PersonalAccessToken $resource
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token   = $this->resource;
        $current = $request->user()?->currentAccessToken();

        return [
            'id'           => $token->getKey(),
            'name'         => $token->device_name ?? $token->name,
            'platform'     => $token->platform,
            'app_version'  => $token->app_version,
            'issued_ip'    => $token->issued_ip,
            'created_at'   => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at'   => $token->expires_at?->toIso8601String(),

            // A token minted from the web dashboard has no device id. Saying
            // so beats showing a blank row the customer cannot place.
            'is_app'     => $token->device_id !== null,
            'is_current' => $current          !== null && $current->getKey() === $token->getKey(),
        ];
    }
}
