<?php

namespace App\Http\Resources;

use App\Models\RemittanceTransfer;
use App\Services\Tax\TaxPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RemittanceTransfer
 */
class RemittanceTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference'            => $this->reference,
            'status'               => $this->status?->value,
            'status_label'         => $this->status?->label(),
            'source_currency'      => $this->source_currency,
            'destination_currency' => $this->destination_currency,
            'send_amount'          => (float) $this->send_amount,
            'fee_amount'           => (float) $this->fee_amount,
            'tax_amount'           => (float) ($this->tax_amount ?? 0),
            'tax'                  => TaxPresenter::stored($this->tax_amount === null ? null : (string) $this->tax_amount, $this->tax_breakdown, (string) $this->source_currency),
            'total_paid'           => (float) $this->total_paid,
            'exchange_rate'        => (float) $this->exchange_rate,
            'receive_amount'       => (float) $this->receive_amount,
            'payout_method'        => $this->payout_method?->value,
            'payout_gateway'       => $this->payout_gateway,
            'dispatch_state'       => $this->dispatch_state,
            'purpose'              => $this->purpose,
            'source_of_funds'      => $this->source_of_funds,
            'failure_reason'       => $this->failure_reason,
            'beneficiary'          => new BeneficiaryResource($this->whenLoaded('beneficiary')),
            'status_history'       => $this->status_history ?? [],
            'submitted_at'         => $this->submitted_at?->toIso8601String(),
            'completed_at'         => $this->completed_at?->toIso8601String(),
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
