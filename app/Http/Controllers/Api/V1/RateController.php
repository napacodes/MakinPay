<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\RateAlert;
use App\Services\Api\RateAlertService;
use App\Services\Api\RateHistoryService;
use App\Services\Money\WalletPinGuard;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where a rate has been, and the customer's standing wishes about where it
 * goes next.
 *
 * An alert that only notifies is a row anybody signed in may create. One
 * that also exchanges is a standing order to move money with no one at the
 * keyboard, so it asks for the wallet PIN now, once, in place of the PIN
 * the exchange screen would ask for later.
 */
class RateController extends Controller
{
    public function __construct(
        private readonly RateHistoryService $history,
        private readonly RateAlertService $alerts,
        private readonly WalletPinGuard $pins,
    ) {}

    /**
     * TO per one FROM at this moment - the hint under a request typed in
     * another currency than the payer holds.
     */
    public function current(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:10'],
            'to'   => ['required', 'string', 'max:10', 'different:from'],
        ]);

        return ApiResponse::ok(['from' => strtoupper($validated['from']), 'to' => strtoupper($validated['to']), 'rate' => $this->alerts->currentRate($validated['from'], $validated['to'])]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'  => ['required', 'string', 'max:10'],
            'to'    => ['required', 'string', 'max:10', 'different:from'],
            'range' => ['sometimes', 'string', Rule::in(array_keys(RateHistoryService::RANGES))],
        ]);

        return ApiResponse::ok($this->history->series($validated['from'], $validated['to'], $validated['range'] ?? '1M'));
    }

    public function index(Request $request): JsonResponse
    {
        $alerts = RateAlert::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ApiResponse::ok(['alerts' => $alerts->map(fn (RateAlert $alert) => $this->body($alert))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $validated = $request->validate([
            'from'          => ['required', 'string', 'max:10'],
            'to'            => ['required', 'string', 'max:10', 'different:from'],
            'target_rate'   => ['required', 'numeric', 'gt:0'],
            'direction'     => ['sometimes', 'string', Rule::in([RateAlert::DIRECTION_ABOVE, RateAlert::DIRECTION_BELOW])],
            'auto_exchange' => ['sometimes', 'boolean'],
            'amount'        => ['required_if:auto_exchange,true', 'nullable', 'numeric', 'gt:0'],
            'wallet_pin'    => ['sometimes', 'nullable', 'string'],
        ]);

        $from = strtoupper(trim($validated['from']));
        $to   = strtoupper(trim($validated['to']));

        if ($this->alerts->currentRate($from, $to) === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('That pair cannot be priced right now.'), ['to' => [__('That pair cannot be priced right now.')]]);
        }

        $auto        = (bool) ($validated['auto_exchange'] ?? false);
        $source      = null;
        $destination = null;

        if ($auto) {
            $source      = $this->alerts->exchangeWallet($user, $from);
            $destination = $this->alerts->exchangeWallet($user, $to);

            if ($source === null || $destination === null) {
                return ApiResponse::error(ApiErrorCode::ValidationFailed, __('You need a wallet in both currencies to exchange automatically.'), ['auto_exchange' => [__('You need a wallet in both currencies to exchange automatically.')]]);
            }

            // The PIN the exchange would have asked for, asked for now.
            $this->pins->assert($user, $validated['wallet_pin'] ?? null);
        }

        $alert = RateAlert::query()->create([
            'user_id'               => $user->id,
            'from_currency'         => $from,
            'to_currency'           => $to,
            'target_rate'           => DecimalMoney::rate((string) $validated['target_rate']),
            'direction'             => $validated['direction'] ?? RateAlert::DIRECTION_ABOVE,
            'auto_exchange'         => $auto,
            'amount'                => $auto ? DecimalMoney::normalize((string) $validated['amount'], DecimalMoney::CALCULATION_SCALE) : null,
            'source_wallet_id'      => $source?->id,
            'destination_wallet_id' => $destination?->id,
            'status'                => RateAlert::STATUS_ACTIVE,
        ]);

        return ApiResponse::created(['alert' => $this->body($alert)]);
    }

    public function destroy(Request $request, int $alert): JsonResponse
    {
        $row = RateAlert::query()->where('user_id', $request->user()->id)->find($alert);

        if ($row === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $row->update(['status' => RateAlert::STATUS_CANCELED]);

        return ApiResponse::ok(['alert' => $this->body($row->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(RateAlert $alert): array
    {
        return [
            'id'             => $alert->id,
            'from'           => $alert->from_currency,
            'to'             => $alert->to_currency,
            'target_rate'    => DecimalMoney::rate((string) $alert->target_rate),
            'direction'      => $alert->direction,
            'auto_exchange'  => $alert->auto_exchange,
            'amount'         => $alert->amount === null ? null : rtrim(rtrim((string) $alert->amount, '0'), '.'),
            'status'         => $alert->status,
            'triggered_rate' => $alert->triggered_rate === null ? null : DecimalMoney::rate((string) $alert->triggered_rate),
            'triggered_at'   => $alert->triggered_at?->toIso8601String(),
            'created_at'     => $alert->created_at?->toIso8601String(),
        ];
    }
}
