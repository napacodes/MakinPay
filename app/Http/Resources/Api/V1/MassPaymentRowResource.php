<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\MassPayment\MassPaymentWarning;
use App\Models\MassPaymentRow;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a mass payment, as its owner's app shows it in the preview and
 * the report.
 *
 * The recipient is only ever masked. `recipient.input` is what the customer
 * typed (they sent it, and a typo has to be visible to be fixed), and
 * `recipient.name` is the masked label the preview showed: first name, last
 * initial and a masked identifier. The matched account's full name and
 * identifiers never leave the server, because api.idempotency stores this
 * body in plain text to replay it.
 *
 * `issue` is a stable code with its translated sentence, the code being what
 * the app branches on. `warnings` lists each duplicate warning with the line
 * it repeats. The three trx ids are the ledger rows' public ids, the ones the
 * transactions endpoints take.
 *
 * Every figure is in the batch currency: set the row's `batch` relation
 * before resolving, and load {@see self::RELATIONS}.
 *
 * @property-read MassPaymentRow $resource
 */
class MassPaymentRowResource extends JsonResource
{
    /** What toArray() reads, to eager-load on any list of rows. */
    public const RELATIONS = [
        'debitTransaction:id,trx_id',
        'creditTransaction:id,trx_id',
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

        return [
            'line' => (int) $row->line_no,

            'recipient' => [
                'input' => $row->recipient_input,
                'type'  => $row->recipient_type,
                'name'  => $row->recipient_label,
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
            'issue'        => $issue === null ? null : [
                'code'    => $issue->value,
                'message' => $row->issueMessage(),
                'hint'    => $row->issueHint()?->value,
            ],
            'warnings' => $this->warnings(),

            'debit_trx_id'  => $row->debitTransaction?->trx_id,
            'credit_trx_id' => $row->creditTransaction?->trx_id,
            'refund_trx_id' => $row->refundTransaction?->trx_id,
            'paid_at'       => $row->paid_at?->toIso8601String(),
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

    /** A figure the line carries, or null on an invalid line that has none. */
    private function money(?string $amount, string $currency): ?string
    {
        return $amount === null ? null : ApiMoney::amount($amount, $currency);
    }
}
