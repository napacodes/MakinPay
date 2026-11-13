<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\AgentOperationType;
use App\Enums\AgentStatus;
use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Agent\AgentOperationResource;
use App\Http\Resources\Api\V1\Agent\AgentResource;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\Wallet;
use App\Services\AgentCommissionRuleService;
use App\Services\AgentOperationService;
use App\Services\WalletService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What an agent can read about their own till.
 *
 * Every query is bounded by the agent profiles this user owns. An agent id is
 * not a secret - it appears in the admin queue and on a QR - so the only thing
 * standing between one agent and another's takings is that bound.
 */
class AgentController extends Controller
{
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly AgentOperationService $operations,
        private readonly WalletService $wallets,
        private readonly AgentCommissionRuleService $commissionRules,
    ) {}

    /**
     * The service already assembles this for the panel and returns models
     * rather than presentation, so it is wrapped rather than reimplemented.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $data = $this->operations->dashboard($request->user());

        /*
         * The stats are BASE-denominated, so they are labelled with the base
         * currency and not the agent's own.
         *
         * This used to label them with the first approved agent's currency.
         * The two commission figures were already base-denominated - they are
         * summed from transactions.amount - so they were already labelled
         * wrong for any agent not trading in the base currency, and X04
         * widened that to the three volume figures by converting them. One
         * label for all five, and it is the one they are actually in.
         */
        return ApiResponse::ok([
            'agents' => AgentResource::collection($data['approvedAgents']),

            'stats' => $this->stats($data['stats'], $data['stats']['currency'] ?? siteCurrency()),

            'recent_operations'    => AgentOperationResource::collection($data['recentOperations']),
            'pending_qr_cash_outs' => AgentOperationResource::collection($data['pendingQrCashOuts']),
        ]);
    }

    /**
     * Every agent profile this user owns, whatever its state.
     *
     * A pending or rejected one is included on purpose: the app has to be able
     * to tell "waiting for approval" from "you are not an agent", and the
     * rejection reason is the only thing that makes a refusal actionable.
     */
    public function profile(Request $request): JsonResponse
    {
        $agents = Agent::query()
            ->where('user_id', $request->user()->id)
            ->with(['currency', 'supportedCurrencies' => fn ($query) => $query->active()])
            ->latest()
            ->get();

        return ApiResponse::ok(AgentResource::collection($agents));
    }

    /**
     * Who the agent is about to serve, before any money moves: the customer
     * an email or wallet id names, in the currency of the till wallet
     * chosen, so a typo is caught at the counter and not in the ledger.
     *
     * Named only as far as the till needs - the name and a masked address.
     * The agent's own account is refused as a customer here as the service
     * refuses it later, so the two agree.
     */
    public function customer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id'  => ['required', 'integer'],
            'wallet_id' => ['required', 'string'],
            'query'     => ['required', 'string', 'max:191'],
        ]);

        [, $till] = $this->till($request, (int) $validated['agent_id'], $validated['wallet_id']);

        if ($till === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $wallet = $this->wallets->getWalletByUserEmailOrWalletUid(trim($validated['query']), (int) $till->currency_id);

        if ($wallet === null || ! $wallet->status || (int) $wallet->currency_id !== (int) $till->currency_id || (int) $wallet->user_id === (int) $request->user()->id) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $customer         = $wallet->user;
        $email            = (string) $customer->email;
        [$local, $domain] = str_contains($email, '@') ? explode('@', $email, 2) : ['', ''];

        return ApiResponse::ok([
            'name'       => $customer->name,
            'username'   => $customer->username,
            'identifier' => $domain === '' ? null : substr($local, 0, 2).'••@'.$domain,
            'currency'   => $till->currency?->code,
        ]);
    }

    /**
     * What the till will earn on an operation, before it is made.
     *
     * Commission comes from the rule engine - assigned rules, global rules
     * with their tiers, fixed parts and caps, then the profile's rate - so
     * the app cannot work it out from the profile's percentage alone. It
     * showed 0.02 for a cash-in the server then paid 0.10 on. This asks the
     * same calculation the operation will use, on the same quantized amount.
     */
    public function commission(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id'  => ['required', 'integer'],
            'wallet_id' => ['required', 'string', 'max:64'],
            'type'      => ['required', Rule::enum(AgentOperationType::class)],
        ]);

        [$agent, $till] = $this->till($request, (int) $validated['agent_id'], $validated['wallet_id']);

        // Only a till that could make the operation gets a quote for it.
        if ($agent === null || $till === null || ! $agent->isApproved() || ! $till->status) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $code = $till->currency?->code;

        // The operation's own amount rules, in the till's currency, so a
        // figure the cash-in would refuse is not quoted either.
        $validated += $request->validate(['amount' => AmountEntry::rules($code)]);

        $amount = Money::quantize((string) $validated['amount'], $code);
        $result = $this->commissionRules->calculate($agent, AgentOperationType::from($validated['type']), $amount, (int) $till->currency_id);

        return ApiResponse::ok([
            'amount'     => ApiMoney::amount($amount, $code),
            'commission' => ApiMoney::amount($result->amount, $code),
            'currency'   => $code,
            'decimals'   => ApiMoney::decimals($code),
        ]);
    }

    /**
     * One of this user's agent profiles and one of their wallets in a
     * currency it serves - the till an operation would be made on.
     *
     * @return array{0: ?Agent, 1: ?Wallet}
     */
    private function till(Request $request, int $agentId, string $walletId): array
    {
        $agent = Agent::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($agentId)
            ->with('supportedCurrencies')
            ->first();

        $till = $agent === null ? null : Wallet::query()
            ->where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->where('uuid', $walletId)->orWhere('id', (int) $walletId))
            ->whereIn('currency_id', $agent->supportedCurrencyIds())
            ->with('currency')
            ->first();

        return [$agent, $till];
    }

    /**
     * The till's own counter QR, for the customer to scan. This is the
     * agent's standing secret, handed only to its owner - the same thing the
     * panel prints, drawn here so the phone can show it at the counter.
     */
    public function qrCode(Request $request): JsonResponse
    {
        $agents = Agent::query()
            ->where('user_id', $request->user()->id)
            ->where('status', AgentStatus::APPROVED)
            ->get();

        return ApiResponse::ok([
            'counters' => $agents->map(fn (Agent $agent): array => [
                'agent_id' => $agent->id,
                'code'     => $agent->agent_code,
                'name'     => $agent->agent_name,
                'enabled'  => (bool) $agent->qr_enabled,
                'url'      => $agent->qr_enabled ? $agent->qrCashOutUrl() : null,
            ])->values(),
        ]);
    }

    public function operations(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['sometimes', 'string', 'max:20'],
            'type'     => ['sometimes', 'string', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $operations = $this->ownedOperations($request)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->with(['customer', 'currency'])
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::paginated(AgentOperationResource::collection($operations));
    }

    public function show(Request $request, string $operation): JsonResponse
    {
        $record = $this->ownedOperations($request)
            ->with(['customer', 'currency'])
            ->where('reference', $operation)
            ->first();

        return $record === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(new AgentOperationResource($record));
    }

    /**
     * Operations belonging to an agent profile this user owns, and no others.
     */
    private function ownedOperations(Request $request): Builder
    {
        return AgentOperation::query()->whereIn(
            'agent_id',
            Agent::query()->where('user_id', $request->user()->id)->select('id'),
        );
    }

    /**
     * The service returns these as floats, because the panel renders them.
     *
     * Counts stay integers; the money figures become strings at the BASE
     * currency's precision, which is what they are denominated in, since a
     * float is not something a ledger figure should arrive as.
     *
     * @param  array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function stats(array $stats, ?string $currency): array
    {
        $money = [
            'today_volume', 'today_cash_in', 'today_cash_out',
            'today_commission', 'month_commission',
        ];

        foreach ($money as $key) {
            $stats[$key] = ApiMoney::amount($stats[$key] ?? 0, $currency);
        }

        $stats['currency'] = $currency;
        $stats['decimals'] = ApiMoney::decimals($currency);

        return $stats;
    }
}
