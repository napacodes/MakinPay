<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Remittance;

use App\Enums\RemittanceStatus;
use App\Http\Resources\BeneficiaryResource;
use App\Models\RemittanceTransfer;
use App\Services\Tax\TaxPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer abroad as the app's receipt, tracking and history screens read
 * it.
 *
 * NOT the integration API's RemittanceTransferResource, which answers in
 * floats, names the gateway and the dispatch state, and hands back the raw
 * status history with its internal notes and context. Integrations parse that
 * shape, so it stays as it is; this one is what a customer's phone needs and
 * nothing more.
 *
 * `status_group` is the app's four-way bucket, so a filter tab and a status
 * colour cannot disagree about which statuses are "in progress" - the server
 * decides once, here, and the app switches on the group.
 *
 * `timeline` is the status history reduced to what happened and when. The
 * notes are left out on purpose: they are written for operators ("Wallet
 * debited; awaiting payout submission.") and some carry a partner's own
 * failure text. The one customer-facing reason is `failure_reason`.
 *
 * `recipient` is the saved recipient, masked, exactly as the recipients list
 * shows it. A transfer keeps its recipient row alive - BeneficiaryService
 * refuses to delete one with history - so it is only ever null for a row
 * written by hand.
 *
 * @property-read RemittanceTransfer $resource
 */
class RemittanceTransferResource extends JsonResource
{
    public const GROUP_IN_PROGRESS = 'in_progress';

    public const GROUP_COMPLETED = 'completed';

    public const GROUP_FAILED = 'failed';

    public const GROUP_REFUNDED = 'refunded';

    /**
     * The statuses each group stands for. The history filter reads this map
     * too, so `?status=failed` returns exactly the rows that say "failed".
     *
     * @return array<string, list<RemittanceStatus>>
     */
    public static function groups(): array
    {
        return [
            self::GROUP_IN_PROGRESS => [
                RemittanceStatus::Draft,
                RemittanceStatus::Quoted,
                RemittanceStatus::Pending,
                RemittanceStatus::Submitted,
                RemittanceStatus::Processing,
            ],
            self::GROUP_COMPLETED => [RemittanceStatus::Completed],
            self::GROUP_FAILED    => [RemittanceStatus::Failed, RemittanceStatus::Canceled],
            self::GROUP_REFUNDED  => [RemittanceStatus::Refunded],
        ];
    }

    public static function groupOf(?RemittanceStatus $status): string
    {
        foreach (self::groups() as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return self::GROUP_IN_PROGRESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transfer    = $this->resource;
        $source      = strtoupper((string) $transfer->source_currency);
        $destination = strtoupper((string) $transfer->destination_currency);
        $tax         = RemittanceFigures::raw($transfer, 'tax_amount');

        return [
            'reference'            => $transfer->reference,
            'status'               => $transfer->status?->value,
            'status_label'         => $transfer->status?->label(),
            'status_group'         => self::groupOf($transfer->status),
            'source_currency'      => $source,
            'destination_currency' => $destination,
            'send_amount'          => RemittanceFigures::amount(RemittanceFigures::raw($transfer, 'send_amount'), $source),
            'fee'                  => RemittanceFigures::amount(RemittanceFigures::raw($transfer, 'fee_amount'), $source),
            'tax'                  => TaxPresenter::stored($tax === null ? null : (string) $tax, $transfer->tax_breakdown, $source),
            'total'                => RemittanceFigures::amount(RemittanceFigures::raw($transfer, 'total_paid'), $source),
            'exchange_rate'        => RemittanceFigures::rate(RemittanceFigures::raw($transfer, 'exchange_rate')),
            'receive_amount'       => RemittanceFigures::amount(RemittanceFigures::raw($transfer, 'receive_amount'), $destination),
            'payout_method'        => $transfer->payout_method?->value,
            'payout_label'         => $transfer->payout_method?->label(),
            'purpose'              => $transfer->purpose,
            'source_of_funds'      => $transfer->source_of_funds,
            'failure_reason'       => $transfer->failure_reason,
            'recipient'            => $transfer->beneficiary === null
                ? null
                : (new BeneficiaryResource($transfer->beneficiary))->resolve($request),
            'timeline'     => $this->timeline(),
            'created_at'   => $transfer->created_at?->toIso8601String(),
            'submitted_at' => $transfer->submitted_at?->toIso8601String(),
            'completed_at' => $transfer->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{status: string, label: string, at: string|null}>
     */
    private function timeline(): array
    {
        $history = is_array($this->resource->status_history) ? $this->resource->status_history : [];

        return array_values(array_map(static function (array $entry): array {
            $status = (string) ($entry['status'] ?? '');

            return [
                'status' => $status,
                'label'  => RemittanceStatus::tryFrom($status)?->label() ?? $status,
                'at'     => isset($entry['timestamp']) ? (string) $entry['timestamp'] : null,
            ];
        }, array_filter($history, 'is_array')));
    }
}
