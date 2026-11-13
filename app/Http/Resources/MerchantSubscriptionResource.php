<?php

namespace App\Http\Resources;

use App\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantSubscription
 */
class MerchantSubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->uuid,
            'external_reference' => $this->external_reference,
            'environment'        => $this->environment?->value,
            'status'             => $this->status?->value,
            'status_label'       => $this->status?->label(),
            'billing_cycle'      => $this->billing_cycle?->value,
            'amount'             => $this->amount,
            'currency_code'      => $this->currency_code,
            'customer'           => [
                'name'  => $this->customer_name,
                'email' => $this->customer_email,
            ],
            'description'          => $this->description,
            'starts_at'            => $this->starts_at?->toIso8601String(),
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end'   => $this->current_period_end?->toIso8601String(),
            'next_billing_at'      => $this->next_billing_at?->toIso8601String(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'cancelled_at'         => $this->cancelled_at?->toIso8601String(),
            'metadata'             => $this->metadata ?? [],
            'latest_invoice'       => new MerchantSubscriptionInvoiceResource($this->whenLoaded('latestInvoice')),
            'invoices'             => MerchantSubscriptionInvoiceResource::collection($this->whenLoaded('invoices')),
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
        ];
    }
}
