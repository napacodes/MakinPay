<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\AgentOperation;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One till movement: a cash-in or a cash-out.
 *
 * The customer is named only as far as an agent handing over cash needs -
 * enough to check they are serving the right person, and no more. The
 * metadata column is not passed through at all: it carries channel markers and
 * whatever a flow chose to record, which is internal bookkeeping rather than
 * anything the app should render.
 *
 * @property-read AgentOperation $resource
 */
class AgentOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $operation = $this->resource;
        $code      = $operation->relationLoaded('currency') ? $operation->currency?->code : null;

        return [
            'reference' => $operation->reference,
            'type'      => $operation->type?->value,
            'status'    => $operation->status?->value,

            'amount'     => ApiMoney::amount($operation->amount, $code),
            'commission' => ApiMoney::amount($operation->commission_amount, $code),
            'currency'   => $code,
            'decimals'   => ApiMoney::decimals($code),

            'customer' => $operation->relationLoaded('customer') && $operation->customer !== null ? [
                'name'     => $operation->customer->name,
                'username' => $operation->customer->username,
            ] : null,

            // The counter QR flow and where the cash stands - awaiting the
            // agent's confirmation, or paid by them - so the customer's app can
            // show a tracker rather than a bare status.
            'channel'       => data_get($operation->metadata, 'channel'),
            'cash_handover' => data_get($operation->metadata, 'cash_handover'),

            'agent' => $operation->relationLoaded('agent') && $operation->agent !== null ? [
                'name' => $operation->agent->agent_name,
                'code' => $operation->agent->agent_code,
            ] : null,

            'note'         => $operation->note,
            'processed_at' => $operation->processed_at?->toIso8601String(),
            'created_at'   => $operation->created_at?->toIso8601String(),
        ];
    }
}
