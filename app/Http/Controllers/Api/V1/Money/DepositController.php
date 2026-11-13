<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Money;

use App\Enums\Api\ApiErrorCode;
use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Deposit\DepositMethodResource;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Models\DepositMethod;
use App\Models\Transaction as TransactionModel;
use App\Models\Wallet as WalletModel;
use App\Services\Money\DepositMoneyService;
use App\Services\Tax\TaxPresenter;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\CustomerPhone;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Putting money in, from a phone.
 *
 * Nothing about the money moves here and nothing about it moves in
 * DepositMoneyService either. A deposit is a pending row until either a
 * gateway's callback settles it or an administrator agrees with a manual
 * claim, and both of those paths already exist and are untouched by this -
 * including the guard against crediting the same deposit twice, which lives
 * in TransactionService::completeTransaction.
 *
 * WHAT THE APP HAS TO DO NEXT is the whole difficulty. The web controller
 * answers with a redirect when the gateway returns a URL and with raw HTML
 * when it returns a form to auto-submit, and for a manual method the gateway
 * hands back a link to the customer's own transaction list - which is a
 * browser's answer to "what now" and is meaningless to an app. So the reply
 * here names the next step instead of performing it: `redirect` with a url
 * for a webview, `html` with a document for it to load, or `instructions`
 * with what the administrator says to pay into. An app that does not
 * recognise the type can show the reference and stop, which is safe.
 */
class DepositController extends Controller
{
    public function __construct(private readonly DepositMoneyService $deposits) {}

    /**
     * The ways in, optionally narrowed to one wallet.
     *
     * A method only works with a wallet in its own currency - PaymentService
     * refuses the pair otherwise - so the filter stops the app offering a
     * choice that cannot be taken.
     */
    public function methods(Request $request): JsonResponse
    {
        $query = DepositMethod::active()->orderBy('name');

        if ($request->has('wallet_id')) {
            $uuid   = $request->input('wallet_id');
            $wallet = is_string($uuid) ? $this->wallet($request, $uuid) : null;

            if ($wallet === null) {
                return ApiResponse::error(ApiErrorCode::ResourceNotFound);
            }

            $query->where('currency', $wallet->currency?->code);
        }

        return ApiResponse::ok([
            'methods' => DepositMethodResource::collection($query->get()),
        ]);
    }

    /**
     * File a deposit.
     */
    public function store(Request $request): JsonResponse
    {
        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $this->wallet($request, $uuid) : null;

        $validated = $request->validate([
            'wallet_id'      => ['required', 'string'],
            'payment_method' => ['required', 'integer'],
            'amount'         => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
            // Read by PaymentService straight off the request for a manual
            // method, exactly as the web form's are. Validated as present and
            // shaped here; which keys matter is the method's own schema.
            'credentials'   => ['sometimes', 'array'],
            'credentials.*' => ['nullable'],
            // Optional for every method; the method's `customer_phone` says
            // whether its gateway sends one on.
            'customer_phone' => CustomerPhone::rules(),
        ]);

        $method = DepositMethod::active()->find($validated['payment_method']);

        /*
         * Resolved through active() before anything is filed. PaymentService
         * resolves the same row with a plain findOrFail, so a method an
         * administrator has switched off still processes there - X09. The
         * catalogue above stops offering it the moment it goes off, and this
         * makes the two agree rather than showing one thing and accepting
         * another. Deliberately stricter than the web form, on a surface with
         * no existing customers to surprise.
         */
        if ($wallet === null || $method === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        /*
         * PaymentService::depositWithPaymentMethod catches everything it
         * throws and rethrows a plain Exception carrying the message, so a
         * NotifyErrorException that means "that amount is outside the limits"
         * arrives here indistinguishable from a gateway being down - and
         * would otherwise be answered with a 500 and no explanation at all.
         *
         * Answered the way the web form answers it: the message, as a refusal.
         * The web has shown the same string to the customer since before this
         * API existed, so this is the same behaviour on a second surface and
         * not a new disclosure. The real fix is for PaymentService to stop
         * flattening its own refusals - X15 - and until it does, neither
         * surface can tell a customer's mistake from a fault.
         */
        try {
            [$transaction, $next] = $this->deposits->deposit($request->user(), [
                'wallet_id'      => $wallet->id,
                'payment_method' => $validated['payment_method'],
                'amount'         => $validated['amount'],
                'customer_phone' => $validated['customer_phone'] ?? null,
            ]);
        } catch (Exception $exception) {
            report($exception);

            return ApiResponse::error(ApiErrorCode::ValidationFailed, $exception->getMessage());
        }

        /*
         * Filed from a phone, so the phone hears when it lands: the same flag
         * the receipt's "notify me" sets, set here for every deposit rather
         * than after the customer has already gone looking.
         */
        $transaction->update(['trx_data' => array_merge($transaction->trx_data ?? [], ['notify_on_status' => true])]);

        return ApiResponse::created([
            'reference' => $transaction->trx_id,
            'status'    => $transaction->status?->value,
            'payable'   => [
                'amount'   => ApiMoney::amount($transaction->payable_amount, $transaction->payable_currency),
                'currency' => $transaction->payable_currency,
                'decimals' => ApiMoney::decimals($transaction->payable_currency),
            ],
            // GF6: the tax inside the payable, a line per rule, in the payable
            // currency - the deposit has no quote, so this is where the app
            // learns what of the payable is tax. Zero with no lines when no
            // rule applies.
            'tax'  => TaxPresenter::transaction($transaction),
            'next' => $this->nextStep($transaction, $method, (string) $next),
        ]);
    }

    /**
     * Attach what the customer was asked for - a slip, a sender name, a
     * transfer id - to a manual deposit they already filed.
     *
     * The app files first so the reference exists to write on the transfer,
     * and comes back with proof once the transfer is made. Only the customer's
     * own pending manual deposit takes it; a settled row is done with.
     */
    public function proof(Request $request, string $transaction): JsonResponse
    {
        $row = TransactionModel::query()
            ->where('user_id', $request->user()->id)
            ->where('trx_id', $transaction)
            ->where('trx_type', TrxType::DEPOSIT)
            ->first();

        if ($row === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if ($row->status !== TrxStatus::PENDING || $row->processing_type !== MethodType::MANUAL) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('Only a pending manual deposit can take proof.'));
        }

        $request->validate([
            'credentials'   => ['required', 'array', 'min:1'],
            'credentials.*' => ['nullable'],
        ]);

        $this->deposits->attachProof($row, $request->input('credentials', []), $request->file('credentials', []));

        return ApiResponse::ok(['transaction' => new TransactionResource($row->refresh())]);
    }

    /**
     * What the app does with what the gateway gave back.
     *
     * A manual method is decided on the transaction's own processing type
     * rather than on the shape of the gateway's answer, because
     * ManualPaymentSystem::deposit() returns a route to the customer's
     * transaction list - a perfectly valid URL that would otherwise be read
     * as "send them to the gateway" and take them nowhere.
     *
     * @return array<string, mixed>
     */
    private function nextStep(TransactionModel $transaction, DepositMethod $method, string $gatewayAnswer): array
    {
        if ($transaction->processing_type === MethodType::MANUAL) {
            return [
                'type'         => 'instructions',
                'instructions' => $method->receive_payment_details,
            ];
        }

        if (filter_var($gatewayAnswer, FILTER_VALIDATE_URL)) {
            return ['type' => 'redirect', 'url' => $gatewayAnswer];
        }

        /*
         * Some gateways answer with a form that posts itself. It is theirs,
         * not ours, so it is handed over as a document for a webview to load
         * rather than parsed for a URL to extract - guessing at somebody
         * else's checkout markup is how a payment ends up pointed at the
         * wrong place.
         */
        return ['type' => 'html', 'html' => $gatewayAnswer];
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
