<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Transaction;
use App\Services\Api\CounterpartyResolver;
use App\Services\Api\Insights\TransactionCategorizer;
use App\Services\Tax\TaxPresenter;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row, as the customer it belongs to is allowed to see it.
 *
 * Whitelisted by hand, and the reason is trx_data: it is an array column
 * holding whatever a provider sent back, which on some gateways includes the
 * raw callback payload. A resource that started from the row and removed what
 * it did not want would put that on a phone the first time a new gateway
 * wrote something unexpected into it. trx_token is excluded for the same
 * reason - it is what a checkout link is addressed by.
 *
 * Every figure is a string at the precision of the currency it is denominated
 * in, and the two denominations are kept apart: amount, fee and net_amount are
 * in `currency`, while payable_amount is in `payable_currency`, which is the
 * wallet's. Quantizing a crypto payout at the base currency's two places would
 * write 0.00000941 away as 0.
 *
 * @property-read Transaction $resource
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trx      = $this->resource;
        $currency = $trx->currency;

        return [
            'trx_id'   => $trx->trx_id,
            'type'     => $trx->trx_type?->value,
            'status'   => $trx->status?->value,
            'flow'     => $trx->amount_flow?->value,
            'method'   => $trx->processing_type?->value,
            'provider' => $trx->provider,

            'amount'     => ApiMoney::amount($trx->amount, $currency),
            'fee'        => ApiMoney::amount($trx->fee, $currency),
            'net_amount' => ApiMoney::amount($trx->net_amount, $currency),
            'currency'   => $currency,
            'decimals'   => ApiMoney::decimals($currency),

            // What actually left or entered the wallet, in the wallet's own
            // unit. Null on a row that never named one.
            'payable' => $trx->payable_currency === null ? null : [
                'amount'   => ApiMoney::amount($trx->payable_amount, $trx->payable_currency),
                'currency' => $trx->payable_currency,
                'decimals' => ApiMoney::decimals($trx->payable_currency),
            ],

            // GF6: the tax charged, in the payable currency, inside the
            // payable amount and apart from the fee; a line per rule, and
            // "0" with no lines when none was.
            'tax' => TaxPresenter::transaction($trx),

            // The FX snapshot's rate, for the receipt's rate line; null when the
            // row predates the snapshot.
            'rate' => $trx->exchange_rate === null ? null : rtrim(rtrim((string) $trx->exchange_rate, '0'), '.'),

            // What happened and when, derived from the row: nothing stores a
            // timeline, and one made from created_at/updated_at is honest.
            'events' => $this->events($trx),

            // A test payment to (or from) a shop in sandbox mode: no wallet
            // moved, so it shows in lists, labelled, and in no total.
            'is_sandbox' => $trx->isSandbox(),

            // The customer asked to hear when a pending row changes state.
            'notify' => (bool) data_get($trx->trx_data, 'notify_on_status', false),

            // The other customer on a send, a receipt or a request.
            'counterparty' => app(CounterpartyResolver::class)->for($trx),

            // Spending category (null for money in and internal moves) and the merchant paid, for Insights.
            'category' => TransactionCategorizer::forUser((int) $trx->user_id)->categoryOf($trx),
            'merchant' => TransactionCategorizer::merchantName($trx),

            'description' => $trx->description,
            'remarks'     => $trx->remarks,
            'created_at'  => $trx->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{key: string, at: string|null, done: bool}>
     */
    private function events(Transaction $trx): array
    {
        $status  = $trx->status?->value ?? 'pending';
        $settled = $status          !== 'pending';
        $changed = $trx->updated_at !== null && $trx->created_at !== null && ! $trx->updated_at->equalTo($trx->created_at);

        return [
            ['key' => 'initiated', 'at' => $trx->created_at?->toIso8601String(), 'done' => true],
            ['key' => 'processing', 'at' => null, 'done' => $settled],
            ['key' => $settled ? $status : 'completed', 'at' => $settled ? ($changed ? $trx->updated_at?->toIso8601String() : $trx->created_at?->toIso8601String()) : null, 'done' => $settled],
        ];
    }
}
