<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\TrxType;
use App\Models\DepositMethod;
use App\Models\Transaction;
use App\Models\WithdrawMethod;
use Illuminate\Database\Eloquent\Model;

/**
 * Which gateway created a transaction.
 *
 * Rows written since the binding landed answer this from their own
 * `gateway_code`. Older ones have to be traced back through the method that
 * created them:
 *
 *     transaction.provider -> {deposit,withdraw}_methods.name
 *                          -> payment_gateway_id -> payment_gateways.code
 *
 * `provider` is a display name an administrator can edit and reuse, so it is
 * only ever used to *find* the relationship, never as the answer. When it
 * matches more than one method, or none, this returns null and the caller
 * fails closed. Guessing here would reintroduce the very thing the machine
 * readable code exists to replace.
 */
class GatewayOriginResolver
{
    /**
     * The canonical gateway code, or null when it cannot be established
     * beyond doubt.
     */
    public function resolve(Transaction $transaction): ?string
    {
        $bound = trim((string) $transaction->gateway_code);

        if ($bound !== '') {
            return $bound;
        }

        $method = $this->originatingMethod($transaction);

        if ($method === null) {
            return null;
        }

        $code = trim((string) ($method->paymentGateway->code ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * The deposit or withdraw method the transaction was created through,
     * only when exactly one candidate survives.
     */
    private function originatingMethod(Transaction $transaction): ?Model
    {
        $provider = trim((string) $transaction->provider);

        if ($provider === '') {
            return null;
        }

        $query = $transaction->trx_type === TrxType::WITHDRAW
            ? WithdrawMethod::query()
            : DepositMethod::query();

        $candidates = (clone $query)->where('name', $provider)->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        // More than one method shares the name. The currency the transaction
        // settled in can still separate them; if it cannot, the origin is
        // genuinely ambiguous and stays that way.
        $currency = trim((string) ($transaction->payable_currency ?? $transaction->currency));

        $narrowed = $candidates->filter(
            fn (Model $method): bool => strcasecmp((string) $method->currency, $currency) === 0
        );

        return $narrowed->count() === 1 ? $narrowed->first() : null;
    }
}
