<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Wallet;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One wallet, identified by its uuid.
 *
 * The primary key never leaves the server. uuid is what every wallet route
 * takes, so an id is of no use to anyone and sending it would only invite a
 * client to start guessing with it.
 *
 * The balance is a string at the currency's own precision. A JSON number goes
 * through a double on the way to Dart, and a double cannot hold 0.1 - which is
 * survivable in a chart and is not survivable in a ledger. `decimals` is sent
 * with it so the app can format without a table of its own.
 *
 * The four capability flags are the currency's roles, which is what decides
 * whether this wallet can send, be withdrawn from, pay a merchant or request
 * money. The app uses them to decide which buttons a wallet card carries.
 *
 * @property-read Wallet $resource
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wallet   = $this->resource;
        $currency = $wallet->currency;

        return [
            'uuid' => $wallet->uuid,
            // The customer's own name for it, when they gave one.
            'label'   => $wallet->label,
            'balance' => ApiMoney::amount($wallet->balance, $currency?->code),
            'status'  => (bool) $wallet->status,

            'currency' => [
                'code'            => $currency?->code,
                'name'            => $currency?->name,
                'symbol'          => $currency?->symbol,
                'symbol_position' => $currency?->symbol_position,
                'type'            => $currency?->type,
                'decimals'        => ApiMoney::decimals($currency?->code),
            ],

            'can' => [
                'send'          => (bool) $wallet->is_sender,
                'withdraw'      => (bool) $wallet->is_withdraw,
                'pay'           => (bool) $wallet->is_payment,
                'request_money' => (bool) $wallet->is_request_money,
            ],

            'created_at' => $wallet->created_at?->toIso8601String(),
        ];
    }
}
