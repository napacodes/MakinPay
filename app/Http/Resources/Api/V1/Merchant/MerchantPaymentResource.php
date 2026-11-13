<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Merchant;

use App\Http\Resources\Api\V1\TransactionResource;
use Illuminate\Http\Request;

/**
 * A payment as the merchant who received it sees it: the ledger row, plus
 * where it came from and who paid.
 *
 * The channel is read from what the flow that wrote the row recorded -
 * a payment link says so in trx_data.source, a checkout created from the
 * app's Charge button carries channel=counter, and a checkout created by
 * the merchant's own integration carries a merchant_id and nothing else.
 * The payer is the name the flow captured, else the customer on the other
 * leg when one exists.
 */
class MerchantPaymentResource extends TransactionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trx  = $this->resource;
        $data = (array) ($trx->trx_data ?? []);
        $row  = parent::toArray($request);

        $channel = match (true) {
            ($data['source'] ?? null)  === 'payment_link' => 'payment_link',
            ($data['channel'] ?? null) === 'counter'      => 'counter',
            isset($data['merchant_id'])                   => 'checkout',
            default                                       => 'wallet',
        };

        $payer = $data['customer_name'] ?? null;
        if (! is_string($payer) || trim($payer) === '') {
            $payer = $row['counterparty']['name'] ?? null;
        }

        return $row + [
            'channel'    => $channel,
            'payer'      => is_string($payer) ? $payer : null,
            'gross'      => $row['payable']['amount'] ?? $row['amount'],
            'link_title' => $channel === 'payment_link' ? ($data['title'] ?? null) : null,
        ];
    }
}
