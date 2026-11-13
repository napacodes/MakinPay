<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Withdraw;

use App\Models\WithdrawAccount;
use App\Services\Money\WithdrawAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One saved payout destination.
 *
 * The stored credentials are NOT sent. They are a bank account number, a
 * crypto address or an uploaded document, and the app has no screen that
 * needs them: it needs enough to tell two accounts apart, which is the name
 * and the masked destination the web panel already shows. Anything more would
 * be a copy of the customer's payout details sitting in a phone's HTTP cache
 * for no reason.
 *
 * The method is included because every screen that lists accounts also has to
 * show what each one will cost and how long it will take.
 *
 * @property-read WithdrawAccount $resource
 */
class WithdrawAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource;
        $method  = $account->withdrawMethod;

        return [
            'id'          => $account->id,
            'name'        => $account->name,
            'destination' => app(WithdrawAccountService::class)->maskedDestination($account),
            'method'      => $method?->exists ? new WithdrawMethodResource($method) : null,
            'created_at'  => $account->created_at?->toIso8601String(),
        ];
    }
}
