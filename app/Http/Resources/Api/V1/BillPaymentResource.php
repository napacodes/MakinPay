<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\BillPayment\BillPaymentStatus;
use App\Models\BillPayment;
use App\Services\BillPayment\BillerDirectory;
use App\Services\Tax\TaxPresenter;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bill payment, as its owner's app shows it: the receipt, and the item a
 * waiting app polls while an operator pays the bill.
 *
 * Identified by its uuid; the primary key never leaves the server. The biller
 * name, category, country and customer number are the snapshots the payment
 * took when it was made, so a biller renamed or switched off since does not
 * rewrite the customer's history.
 *
 * The token is sent only once the payment is completed. Every endpoint that
 * returns this resource loads the payment through the caller's own relation,
 * so the token only ever reaches the customer who paid for it. A token the
 * app can no longer decrypt reads as none rather than breaking the receipt.
 *
 * `transaction_id` and `refund_transaction_id` are the ledger rows' public
 * trx ids, the ones the transactions endpoints take.
 *
 * Load with {@see self::RELATIONS}.
 *
 * @property-read BillPayment $resource
 */
class BillPaymentResource extends JsonResource
{
    /** What toArray() reads, to eager-load on any list of payments. */
    public const RELATIONS = ['biller.provider', 'provider', 'transaction', 'refundTransaction'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment    = $this->resource;
        $status     = $payment->status;
        $currency   = (string) $payment->currency;
        $tokenLabel = trim((string) $payment->biller?->token_label);

        return [
            'id'           => $payment->uuid,
            'reference'    => $payment->reference,
            'status'       => $status?->value,
            'status_label' => $status?->customerLabel(),

            'biller' => [
                'id'       => $payment->biller_id,
                'name'     => $payment->biller_name,
                'logo_url' => filled($payment->biller?->logo) ? asset((string) $payment->biller->logo) : null,
                'category' => $payment->category_code,
                'country'  => $payment->country,
            ],

            'customer_number_label' => $payment->customer_number_label,
            'customer_number'       => $payment->customer_number,
            'item_name'             => $payment->item_name,

            'bill_amount'   => ApiMoney::amount((string) $payment->bill_amount, (string) $payment->bill_currency),
            'bill_currency' => $payment->bill_currency,
            'amount'        => ApiMoney::amount((string) $payment->amount, $currency),
            'fee'           => ApiMoney::amount((string) $payment->fee, $currency),
            'tax'           => TaxPresenter::stored($payment->tax_amount === null ? null : (string) $payment->tax_amount, $payment->tax_breakdown, (string) $currency),
            'total'         => ApiMoney::amount((string) $payment->total_amount, $currency),
            'currency'      => $currency,

            'receipt_reference' => $payment->receipt_reference,
            'token'             => $status === BillPaymentStatus::Completed
                ? rescue(fn (): ?string => $payment->token, null)
                : null,
            'token_label' => $tokenLabel !== '' ? $tokenLabel : null,

            'processing_note' => app(BillerDirectory::class)->processingNote($payment->biller, $payment),
            'instructions'    => $payment->biller?->instructions,
            'failure_reason'  => $payment->failure_reason,
            'refunded'        => $status === BillPaymentStatus::Failed && $payment->refund_transaction_id !== null,

            'transaction_id'        => $payment->transaction?->trx_id,
            'refund_transaction_id' => $payment->refundTransaction?->trx_id,
            'created_at'            => $payment->created_at?->toIso8601String(),
            'processed_at'          => $payment->processed_at?->toIso8601String(),
        ];
    }
}
