<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PayoutMethod;
use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Remittance\ConfirmTransferRequest;
use App\Http\Requests\Remittance\QuoteRequest;
use App\Http\Resources\RemittanceQuoteResource;
use App\Http\Resources\RemittanceTransferResource;
use App\Models\Beneficiary;
use App\Models\RemittanceCorridor;
use App\Models\RemittanceQuote;
use App\Models\RemittanceTransfer;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\Remittance\PayoutRouter;
use App\Services\Remittance\QuoteService;
use App\Services\Remittance\RemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RemittanceController extends Controller
{
    public function corridors(): JsonResponse
    {
        $availableGateways = app(PayoutGatewayFactory::class)->availableCodes();
        $corridors         = RemittanceCorridor::query()
            ->active()
            ->whereIn('payout_gateway', $availableGateways)
            ->get()
            ->map(fn ($corridor) => [
                'id'                     => $corridor->id,
                'name'                   => $corridor->name,
                'source_country'         => $corridor->source_country,
                'source_currency'        => $corridor->source_currency,
                'destination_country'    => $corridor->destination_country,
                'destination_currency'   => $corridor->destination_currency,
                'allowed_payout_methods' => $corridor->allowed_payout_methods,
                'fixed_fee'              => (float) $corridor->fixed_fee,
                'percent_fee'            => (float) $corridor->percent_fee,
                'fx_spread_percent'      => (float) $corridor->fx_spread_percent,
                'min_amount'             => (float) $corridor->min_amount,
                'max_amount'             => (float) $corridor->max_amount,
            ]);

        return response()->json(['data' => $corridors]);
    }

    public function quote(QuoteRequest $request, QuoteService $service, PayoutRouter $router): JsonResponse
    {
        try {
            $corridor = $router->findActiveById((int) $request->corridor_id);

            $quote = $service->quote(
                user: $request->user(),
                corridor: $corridor,
                sendAmount: (string) $request->send_amount,
                payoutMethod: PayoutMethod::from($request->payout_method),
                beneficiaryId: $request->filled('beneficiary_id') ? (int) $request->beneficiary_id : null,
            );

            return response()->json(['data' => new RemittanceQuoteResource($quote)]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('Unable to create a remittance quote right now.')], 503);
        }
    }

    public function send(ConfirmTransferRequest $request, RemittanceService $remittance): JsonResponse
    {
        try {
            $quote       = RemittanceQuote::where('quote_id', $request->quote_id)->firstOrFail();
            $beneficiary = Beneficiary::findOrFail($request->beneficiary_id);

            $transfer = $remittance->send(
                user: $request->user(),
                quote: $quote,
                beneficiary: $beneficiary,
                context: [
                    'wallet_id'       => (int) $request->wallet_id,
                    'purpose'         => $request->purpose,
                    'source_of_funds' => $request->source_of_funds,
                    'idempotency_key' => $request->validated('idempotency_key'),
                ],
            );

            $replayed = (bool) $transfer->getAttribute('idempotent_replay');

            return response()
                ->json(['data' => new RemittanceTransferResource($transfer->load('beneficiary'))], $replayed ? 200 : 201)
                ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('Unable to submit the remittance right now.')], 503);
        }
    }

    public function track(Request $request, string $reference): JsonResponse
    {
        $transfer = RemittanceTransfer::with(['beneficiary'])
            ->forUser($request->user()->id)
            ->where('reference', $reference)
            ->first();

        if (! $transfer) {
            return response()->json(['message' => __('Transfer not found.')], 404);
        }

        return response()->json(['data' => new RemittanceTransferResource($transfer)]);
    }

    public function history(Request $request): JsonResponse
    {
        $transfers = RemittanceTransfer::with(['beneficiary'])
            ->forUser($request->user()->id)
            ->latest()
            ->paginate((int) min(100, max(5, $request->integer('per_page', 20))));

        return RemittanceTransferResource::collection($transfers)->response();
    }
}
