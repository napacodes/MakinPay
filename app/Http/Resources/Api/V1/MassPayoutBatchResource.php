<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Models\MassPaymentBatch;
use Illuminate\Http\Request;

/**
 * One mass payout, as its owner's app shows it: everything a wallet batch
 * carries ({@see MassPaymentBatchResource}), plus the payout terms it was
 * built on and where approval stands.
 *
 * `payout.method` is the method as it was when the draft was built, read
 * from the batch's frozen terms, so a method renamed or deleted since still
 * reads as the batch was priced. `can.stop` also holds while the batch waits
 * for approval, since the owner may withdraw it then; it is a hint only.
 *
 * Load with {@see MassPaymentBatchResource::RELATIONS}.
 *
 * @property-read MassPaymentBatch $resource
 */
class MassPayoutBatchResource extends MassPaymentBatchResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data   = parent::toArray($request);
        $batch  = $this->resource;
        $status = $batch->status();
        $terms  = is_array($batch->metadata['payout'] ?? null) ? $batch->metadata['payout'] : [];

        $data['kind']   = 'payout';
        $data['payout'] = [
            'method' => [
                'id'   => isset($terms['method_id']) ? (int) $terms['method_id'] : null,
                'name' => isset($terms['method_name']) ? (string) $terms['method_name'] : null,
            ],
            'driver'            => (string) $batch->payout_driver,
            'fee_mode'          => isset($terms['fee_mode']) ? (string) $terms['fee_mode'] : null,
            'approval_required' => (bool) $batch->approval_required,
            'approved_at'       => $batch->approved_at?->toIso8601String(),
        ];

        $data['can']['stop'] = in_array($status, [
            MassPaymentBatchStatus::AwaitingApproval,
            MassPaymentBatchStatus::Queued,
            MassPaymentBatchStatus::Processing,
        ], true) && $batch->stop_requested_at === null;

        return $data;
    }
}
