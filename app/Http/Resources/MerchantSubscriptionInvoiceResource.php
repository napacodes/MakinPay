<?php

namespace App\Http\Resources;

use App\Models\MerchantSubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantSubscriptionInvoice
 */
class MerchantSubscriptionInvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->uuid,
            'reference'           => $this->reference,
            'sequence'            => $this->sequence,
            'status'              => $this->status?->value,
            'status_label'        => $this->status?->label(),
            'transaction_id'      => $this->transaction_id,
            'amount'              => $this->amount,
            'currency_code'       => $this->currency_code,
            'period_start'        => $this->period_start?->toIso8601String(),
            'period_end'          => $this->period_end?->toIso8601String(),
            'due_at'              => $this->due_at?->toIso8601String(),
            'payment_url'         => $this->payment_url,
            'checkout_expires_at' => $this->checkout_expires_at?->toIso8601String(),
            'paid_at'             => $this->paid_at?->toIso8601String(),
            'failed_at'           => $this->failed_at?->toIso8601String(),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
