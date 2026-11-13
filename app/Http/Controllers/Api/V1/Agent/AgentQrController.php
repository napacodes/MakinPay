<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\AgentStatus;
use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\CustomerQrCashOutRequest;
use App\Http\Resources\Api\V1\Agent\AgentOperationResource;
use App\Http\Resources\Api\V1\Agent\AgentResource;
use App\Http\Resources\Api\V1\WalletResource;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Services\AgentOperationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The counter QR, from both sides of it.
 *
 * The scan and the confirmation belong to the CUSTOMER, not the agent, so
 * these two routes carry no agent role gate - anybody signed in can be cashed
 * out at a counter. Only mark-paid is the agent's, and that one is gated.
 *
 * The token in the URL is the agent's standing secret. It is resolved exactly
 * as the web flow resolves it: enabled, approved, and matching - so a
 * suspended agent's QR stops working the moment an administrator says so,
 * without anybody having to reprint it.
 */
class AgentQrController extends Controller
{
    public function __construct(private readonly AgentOperationService $operations) {}

    /**
     * What the customer sees after scanning.
     *
     * Their own wallets, narrowed to the currencies this agent deals in, so
     * the app can offer a choice that will not be refused a moment later.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $agent = $this->qrAgent($token);

        if ($agent === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $wallets = $request->user()
            ->wallets()
            ->with('currency.roles')
            ->active()
            ->whereIn('currency_id', $agent->supportedCurrencyIds())
            ->get();

        return ApiResponse::ok([
            'agent'   => new AgentResource($agent),
            'wallets' => WalletResource::collection($wallets),
        ]);
    }

    /**
     * The customer commits: their wallet is debited and the agent owes cash.
     *
     * The request class requires a wallet PIN, which is what stops a stolen
     * phone emptying a wallet at the nearest counter. It is the web form's own
     * rule, reused rather than restated.
     */
    public function cashOut(CustomerQrCashOutRequest $request, string $token): JsonResponse
    {
        $agent = $this->qrAgent($token);

        if ($agent === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $operation = $this->operations->customerQrCashOut($request->user(), $agent, $request->validated());

        return ApiResponse::created(
            new AgentOperationResource($operation->load(['agent', 'customer', 'currency']))
        );
    }

    /**
     * The customer's side of one cash-out: where the hand-over stands. Scoped
     * to operations this customer is party to, so a reference guessed by
     * somebody else answers not-found.
     */
    public function status(Request $request, string $operation): JsonResponse
    {
        $record = AgentOperation::query()
            ->where('customer_user_id', $request->user()->id)
            ->where('reference', $operation)
            ->with(['agent', 'customer', 'currency'])
            ->first();

        return $record === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(new AgentOperationResource($record));
    }

    /**
     * The agent confirms the cash actually changed hands.
     *
     * Scoped to the caller's own agents here as well as inside the service.
     * The service refuses a stranger's operation with a message; scoping the
     * lookup means the answer is a plain not-found instead, which does not
     * confirm that the reference exists.
     */
    public function markPaid(Request $request, string $operation): JsonResponse
    {
        $record = AgentOperation::query()
            ->whereIn('agent_id', Agent::query()->where('user_id', $request->user()->id)->select('id'))
            ->where('reference', $operation)
            ->first();

        if ($record === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $updated = $this->operations->markQrCashOutPaid($request->user(), $record);

        return ApiResponse::ok(
            new AgentOperationResource($updated->load(['customer', 'currency']))
        );
    }

    /**
     * Enabled, approved and matching - the same three conditions the web flow
     * applies, so a QR stops working the moment an administrator disables it.
     */
    private function qrAgent(string $token): ?Agent
    {
        // The token from a scanned counter QR, or the agent code printed
        // beside it and typed by hand - the app offers both, and the
        // resolver behind the scanner already accepts both.
        return Agent::query()
            ->where(fn ($q) => $q->where('qr_token', $token)->orWhere('agent_code', $token))
            ->where('qr_enabled', true)
            ->where('status', AgentStatus::APPROVED)
            ->with(['user', 'currency', 'supportedCurrencies'])
            ->first();
    }
}
