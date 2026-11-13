<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Merchant;

use App\Models\PaymentLink;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment link, as its owner sees it.
 *
 * The token IS included here, unlike the agent QR token, and the difference is
 * what each one authorises. A payment link token addresses a page that invites
 * a stranger to pay - it is meant to be shared, and a merchant who cannot read
 * it cannot send it to anybody. An agent QR token authorises taking money from
 * the till behind it.
 *
 * @property-read PaymentLink $resource
 */
class PaymentLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $link = $this->resource;
        $code = $link->relationLoaded('currency') ? $link->currency?->code : null;

        return [
            'token'       => $link->token,
            'title'       => $link->title,
            'description' => $link->description,
            'status'      => $link->status?->value ?? $link->status,
            // The shop it takes money for, so an edit keeps it there.
            'merchant_id' => $link->merchant_id === null ? null : (int) $link->merchant_id,

            'amount'     => ApiMoney::amount($link->amount, $code),
            'min_amount' => $link->min_amount === null ? null : ApiMoney::amount($link->min_amount, $code),
            'max_amount' => $link->max_amount === null ? null : ApiMoney::amount($link->max_amount, $code),
            'currency'   => $code,
            'decimals'   => ApiMoney::decimals($code),

            'max_payments'   => $link->max_payments === null ? null : (int) $link->max_payments,
            'payments_count' => (int) ($link->payments_count ?? 0),

            // Net collected from the completed rows that name this link, set
            // by the listing; a link read on its own says 0 rather than lying.
            'collected' => ApiMoney::amount($link->getAttribute('collected') ?? 0, $code),

            'expires_at' => $link->expires_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
