<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\MassPayment\MassPaymentDispatchState;
use App\Enums\MassPayment\MassPaymentWarning;
use App\Models\MassPaymentRow;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a mass payout, as its owner's app shows it.
 *
 * The account is only ever the masked label (`account.name`): the details
 * never leave the server here, because api.idempotency stores this body in
 * plain text to replay it. `payout.state` is where the line stands with its
 * provider, with the owner's wording in `payout.label`; the provider's
 * reference is shown once the line is settled.
 *
 * Every figure is in the batch currency: set the row's `batch` relation
 * before resolving, and load {@see self::RELATIONS}.
 *
 * @property-read MassPaymentRow $resource
 */
class MassPayoutRowResource extends JsonResource
{
    /** What toArray() reads, to eager-load on any list of rows. */
    public const RELATIONS = [
        'debitTransaction:id,trx_id',
        'refundTransaction:id,trx_id',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row      = $this->resource;
        $currency = (string) $row->batch?->currency_code;
        $issue    = $row->issue();
        $state    = $row->dispatchState();

        return [
            'line' => (int) $row->line_no,

            'account' => [
                'name' => $row->recipient_label,
            ],

            'amount_input' => $row->amount_input,
            'amount'       => $this->money($row->entered_amount, $currency),
            'fee'          => $this->money($row->wallet_fee, $currency),
            'net'          => $this->money($row->net_amount, $currency),
            'total'        => $this->money($row->payable_amount, $currency),
            'currency'     => $currency,
            'note'         => $row->note,
            'reference'    => $row->client_reference,

            'status'       => $row->status()?->value ?? (string) $row->getAttribute('status'),
            'under_review' => (bool) $row->needs_review,
            'payout'       => [
                'state'     => $state?->value,
                'label'     => $state?->customerLabel(),
                'reference' => $state === MassPaymentDispatchState::Settled ? $row->provider_reference : null,
            ],
            'issue' => $issue === null ? null : [
                'code'    => $issue->value,
                'message' => $row->issueMessage(),
            ],
            'warnings' => $this->warnings(),

            'debit_trx_id'  => $row->debitTransaction?->trx_id,
            'refund_trx_id' => $row->refundTransaction?->trx_id,
            'settled_at'    => $row->settled_at?->toIso8601String(),
            'failed_at'     => $row->failed_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{code: string, message: string, duplicate_of_line: int|null}>
     */
    private function warnings(): array
    {
        $warnings = [];

        foreach ((array) ($this->resource->warnings ?? []) as $warning) {
            $code = MassPaymentWarning::tryFrom((string) data_get($warning, 'code'));

            if ($code === null) {
                continue;
            }

            $line = data_get($warning, 'duplicate_of_line');
            $line = is_numeric($line) ? (int) $line : null;

            $warnings[] = [
                'code'              => $code->value,
                'message'           => $code->message($line),
                'duplicate_of_line' => $line,
            ];
        }

        return $warnings;
    }

    private function money(?string $amount, string $currency): ?string
    {
        return $amount === null ? null : ApiMoney::amount($amount, $currency);
    }
}
