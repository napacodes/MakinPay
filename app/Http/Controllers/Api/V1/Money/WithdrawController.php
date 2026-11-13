<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\StoreWithdrawAccountRequest;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Http\Resources\Api\V1\Withdraw\WithdrawAccountResource;
use App\Http\Resources\Api\V1\Withdraw\WithdrawMethodResource;
use App\Models\Wallet as WalletModel;
use App\Models\WithdrawAccount;
use App\Models\WithdrawMethod;
use App\Models\WithdrawSchedule;
use App\Services\Api\WithdrawPreviewService;
use App\Services\Money\WithdrawAccountService;
use App\Services\Money\WithdrawMoneyService;
use App\Support\AmountEntry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taking money off the platform, from a phone.
 *
 * Five endpoints, because a payout is five questions: which methods exist,
 * what does this one need to know, which destinations have I already saved,
 * save a new one, and send the money. The web form answers the same five
 * across a page, two Ajax calls and a modal.
 *
 * Everything that decides whether the payout happens is in
 * WithdrawMoneyService, and the money itself moves in
 * PaymentService::withdrawMoney, which locks the wallet. Neither is restated
 * here. This controller resolves what the request is talking about, refuses
 * what it cannot find, and hands over.
 *
 * Wallets are addressed by uuid, as everywhere else in this API. Payout
 * accounts and methods keep their primary keys: an account is the customer's
 * own row and every query for one is scoped to them, and a method is a public
 * catalogue entry the administrator publishes.
 */
class WithdrawController extends Controller
{
    public function __construct(
        private readonly WithdrawMoneyService $withdrawals,
        private readonly WithdrawAccountService $accounts,
        private readonly WithdrawPreviewService $previews,
    ) {}

    /**
     * What a payout would cost and when it would land, before it is filed.
     *
     * Nothing moves. The same wallet and destination resolution as the
     * payout itself, so a preview cannot describe a payout the store would
     * refuse for belonging to somebody else.
     */
    public function preview(Request $request): JsonResponse
    {
        $walletUuid = $request->input('wallet_id');
        $wallet     = is_string($walletUuid) ? $this->wallet($request, $walletUuid) : null;

        $validated = $request->validate([
            'wallet_id'  => ['required', 'string'],
            'account_id' => ['required', 'integer'],
            'amount'     => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
        ]);

        $account = WithdrawAccount::query()
            ->where('user_id', $request->user()->id)
            ->with(['withdrawMethod'])
            ->find($validated['account_id']);

        if ($wallet === null || $account?->withdrawMethod === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if ($account->withdrawMethod->currency !== $wallet->currency?->code) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Withdrawal account currency does not match the selected wallet.'), ['account_id' => [__('Withdrawal account currency does not match the selected wallet.')]]);
        }

        return ApiResponse::ok(['quote' => $this->previews->quote($request->user(), $wallet, $account, (string) $validated['amount'])]);
    }

    /**
     * The payout methods on offer, optionally narrowed to one wallet.
     *
     * A method only works with a wallet in the same currency - the service
     * refuses the pair otherwise - so the filter exists to stop the app
     * offering a choice that cannot be taken.
     */
    public function methods(Request $request): JsonResponse
    {
        $query = WithdrawMethod::active()->orderBy('name');

        if ($request->filled('wallet_id')) {
            $walletUuid = $request->input('wallet_id');
            $wallet     = is_string($walletUuid) ? $this->wallet($request, $walletUuid) : null;

            if ($wallet === null) {
                return ApiResponse::error(ApiErrorCode::ResourceNotFound);
            }

            $query->where('currency', $wallet->currency?->code);
        }

        return ApiResponse::ok([
            'open_today'  => WithdrawSchedule::isWithdrawEnabledToday(),
            'closed_days' => WithdrawSchedule::query()
                ->where('status', false)
                ->pluck('day')
                ->values(),
            'methods' => WithdrawMethodResource::collection($query->get()),
        ]);
    }

    /**
     * The credential fields one method needs.
     *
     * The web panel answers this with rendered HTML, which is of no use to an
     * app. The same schema is sent as data instead - the normaliser both
     * already run through - so the app builds its own form and the two cannot
     * disagree about which fields exist.
     */
    public function fields(Request $request, int $method): JsonResponse
    {
        $withdrawMethod = WithdrawMethod::active()->find($method);

        if ($withdrawMethod === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        return ApiResponse::ok([
            'method_id'   => $withdrawMethod->id,
            'method_name' => $withdrawMethod->name,
            'fields'      => WithdrawMethodResource::fields($withdrawMethod),
        ]);
    }

    /**
     * The customer's saved destinations, optionally narrowed to the ones a
     * given wallet can actually pay out to.
     */
    public function accounts(Request $request): JsonResponse
    {
        $query = WithdrawAccount::query()
            ->where('user_id', $request->user()->id)
            ->with(['withdrawMethod'])
            ->orderByDesc('id');

        if ($request->filled('wallet_id')) {
            $walletUuid = $request->input('wallet_id');
            $wallet     = is_string($walletUuid) ? $this->wallet($request, $walletUuid) : null;

            if ($wallet === null) {
                return ApiResponse::error(ApiErrorCode::ResourceNotFound);
            }

            $query->whereHas(
                'withdrawMethod',
                fn ($method) => $method->where('currency', $wallet->currency?->code),
            );
        }

        return ApiResponse::ok([
            'accounts' => WithdrawAccountResource::collection($query->get()),
        ]);
    }

    /**
     * Save a destination.
     *
     * The web form's own request class does the validating, unchanged: it is
     * the thing that knows a method must be active, must match a currency the
     * customer holds a wallet in, and that a crypto address must be valid for
     * its network. A second copy of those rules for the app would be a second
     * copy to forget to update.
     */
    public function storeAccount(StoreWithdrawAccountRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $method    = $request->withdrawMethod() ?? WithdrawMethod::active()->findOrFail($validated['method_id']);

        $account = $this->accounts->create(
            $request->user(),
            $method,
            $validated['account_name'],
            $request->input('credentials', []),
            $request->file('credentials', []),
        );

        return ApiResponse::created([
            'account' => new WithdrawAccountResource($account->load('withdrawMethod')),
        ]);
    }

    /**
     * Send the money.
     *
     * The amount is typed in the payout METHOD's currency, which must match
     * the wallet's - the same rule the web form follows. Its floor is one unit
     * at that currency's own precision, so a BTC payout is not refused for
     * being under a dollar.
     *
     * The PIN field is validated here rather than in the service because a
     * client expects a field error under the input it belongs to. Whether the
     * PIN is CORRECT is the service's question, and stays there: a caller
     * without a form request still needs that check.
     */
    public function store(Request $request): JsonResponse
    {
        /*
         * Resolved before validating, because the amount's own rules depend on
         * the currency the wallet is kept in - a flat floor of 0.01 rejects
         * every BTC payout worth less than six hundred dollars.
         *
         * Guarded on is_string rather than on filled(): a client sending an
         * array here would otherwise be cast to one on the way into the query.
         * A non-string fails the rules below in the same pass as everything
         * else, so the caller still gets one error bag rather than two.
         */
        $walletUuid = $request->input('wallet_id');
        $wallet     = is_string($walletUuid) ? $this->wallet($request, $walletUuid) : null;

        $validated = $request->validate([
            'wallet_id'  => ['required', 'string'],
            'account_id' => ['required', 'integer'],
            'amount'     => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if ($request->user()->hasWalletPin()) {
            $validated['wallet_pin'] = $request->validate([
                'wallet_pin' => ['required', 'digits:6'],
            ])['wallet_pin'];
        }

        /*
         * The service looks both up again, scoped to the caller. Handing it
         * the primary key rather than the uuid keeps its signature identical
         * to the one the web form uses, and the second lookup is deliberate:
         * two independent owner checks cost one indexed read.
         */
        $transaction = $this->withdrawals->withdraw($request->user(), [
            'account_id' => $validated['account_id'],
            'wallet_id'  => $wallet->id,
            'amount'     => $validated['amount'],
            'wallet_pin' => $validated['wallet_pin'] ?? null,
        ]);

        return ApiResponse::created([
            'transaction' => new TransactionResource($transaction),
        ]);
    }

    /**
     * One of the caller's own wallets, by uuid. Somebody else's is not found
     * rather than refused, because a refusal confirms it exists.
     */
    private function wallet(Request $request, string $uuid): ?WalletModel
    {
        return WalletModel::query()
            ->where('user_id', $request->user()->id)
            ->where('uuid', $uuid)
            ->with(['currency'])
            ->first();
    }
}
