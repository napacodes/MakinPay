<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Enums\AmountFlow;
use App\Enums\Api\ApiErrorCode;
use App\Enums\EnvironmentMode;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MerchantChargeRequest;
use App\Http\Resources\Api\V1\Merchant\MerchantPaymentResource;
use App\Http\Resources\Api\V1\Merchant\MerchantResource;
use App\Http\Resources\Api\V1\Merchant\PaymentLinkResource;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Services\Api\MerchantTakings;
use App\Services\MerchantCheckoutService;
use App\Services\MerchantReceiptService;
use App\Services\TransactionService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What a merchant can read about their own business, and the one thing
 * they can start from the phone: a counter charge.
 *
 * Every profile query starts from the merchants this user owns. The takings
 * are read from the ledger and scoped on user_id instead, because a payment
 * writes its row against the receiving USER rather than against a merchant -
 * the two scopes are not interchangeable and neither one covers the other.
 *
 * A received payment is a RECEIVE_PAYMENT row: that is what a checkout and a
 * payment link both write to the merchant's ledger. A PAYMENT row on the
 * same user is the merchant paying somebody else, and is not takings.
 */
class MerchantController extends Controller
{
    private const MAX_PER_PAGE = 50;

    /**
     * How long a counter charge stays payable. The customer is standing at
     * the counter; a QR that could still be paid an hour later is a QR the
     * shop has forgotten about.
     */
    private const CHARGE_MINUTES = 15;

    public function profile(Request $request): JsonResponse
    {
        $merchants = $this->ownedMerchants($request)
            ->with(['currency', 'supportedCurrencies'])
            ->latest()
            ->get();

        return ApiResponse::ok(MerchantResource::collection($merchants));
    }

    /**
     * The headline figures, grouped by currency rather than added together.
     *
     * A merchant taking payment in two currencies has two totals. One number
     * covering both would be a figure nobody could reconcile against anything.
     */
    public function stats(Request $request, MerchantTakings $takings): JsonResponse
    {
        return ApiResponse::ok([
            'merchants'     => $this->ownedMerchants($request)->count(),
            'payment_links' => PaymentLink::query()->where('user_id', $request->user()->id)->count(),
            'takings'       => $takings->for($request->user()),
        ]);
    }

    /**
     * Payments received, newest first; `today=1` narrows to today's.
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'today'    => ['sometimes', 'boolean'],
        ]);

        $transactions = $this->received($request)
            ->when($request->boolean('today'), fn (Builder $q) => $q->where('created_at', '>=', now()->startOfDay()))
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        return ApiResponse::paginated(MerchantPaymentResource::collection($transactions));
    }

    /**
     * The links with what each one has brought in: the count the link keeps,
     * and the net collected from the completed rows that name it, sandbox
     * payments aside since their net was never credited.
     */
    public function paymentLinks(Request $request): JsonResponse
    {
        $links = PaymentLink::query()
            ->where('user_id', $request->user()->id)
            ->with('currency')
            ->latest('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 25), self::MAX_PER_PAGE))
            ->withQueryString();

        $ids = collect($links->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $collected = [];
        if ($ids !== []) {
            $rows = $this->received($request)
                ->where('status', TrxStatus::COMPLETED)
                ->withoutSandbox()
                ->whereIn('trx_data->payment_link_id', $ids)
                ->get(['trx_data', 'net_amount', 'amount']);

            foreach ($rows as $row) {
                $linkId             = (int) data_get($row->trx_data, 'payment_link_id', 0);
                $collected[$linkId] = DecimalMoney::add($collected[$linkId] ?? '0', $row->net_amount ?? $row->amount ?? 0);
            }
        }

        foreach ($links->items() as $link) {
            $link->setAttribute('collected', $collected[(int) $link->id] ?? '0');
        }

        return ApiResponse::paginated(PaymentLinkResource::collection($links));
    }

    /**
     * A counter charge: a checkout for an amount, so the customer can scan
     * its reference and pay from their wallet - the same checkout the
     * merchant's own integration would create, made from the phone.
     *
     * No callback or redirect: the app learns the outcome by reading the
     * charge back, and the customer's app confirms on their side.
     */
    public function charge(MerchantChargeRequest $request, MerchantCheckoutService $checkouts): JsonResponse
    {
        $merchant = $this->ownedMerchants($request)
            ->with(['user', 'currency', 'supportedCurrencies'])
            ->whereKey($request->integer('merchant_id'))
            ->first();

        if ($merchant === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if (! $merchant->isApproved() && ! $merchant->canTrySandbox()) {
            throw new NotifyErrorException(__('Only an approved shop can take payments.'));
        }

        // A shop still in review tries it out in sandbox whatever its mode
        // says: real money waits for approval.
        $mode        = $merchant->current_mode?->value ?? $merchant->current_mode;
        $environment = $merchant->isApproved()
            ? (EnvironmentMode::tryFrom((string) $mode) ?? EnvironmentMode::PRODUCTION)
            : EnvironmentMode::SANDBOX;
        $currency = strtoupper(trim((string) ($request->input('currency_code') ?: $merchant->currency?->code)));

        $result = $checkouts->create($merchant, $environment, [
            'ref_trx'          => 'APP-'.Str::upper(Str::random(10)),
            'payment_amount'   => $request->input('amount'),
            'currency_code'    => $currency,
            'description'      => $request->input('description') ?: __('Counter payment to :shop', ['shop' => $merchant->business_name]),
            'ipn_url'          => null,
            'cancel_redirect'  => null,
            'success_redirect' => null,
        ], [
            'channel'     => 'counter',
            'created_via' => 'app',
        ]);

        $transaction = $result['transaction'];
        // A short public page for the counter QR: the signed payment_url is
        // over 300 characters, too dense a code to scan off a phone screen
        // with the logo in the middle. The token is random and only opens
        // this one checkout's page.
        $transaction->forceFill([
            'expires_at' => now()->addMinutes(self::CHARGE_MINUTES),
            'trx_token'  => Str::random(32),
        ])->save();

        return ApiResponse::created([
            'reference'   => $transaction->trx_id,
            'amount'      => ApiMoney::amount($transaction->payable_amount, $currency),
            'fee'         => ApiMoney::amount($result['fee'], $currency),
            'net'         => ApiMoney::amount($result['net_amount'], $currency),
            'currency'    => $currency,
            'decimals'    => ApiMoney::decimals($currency),
            'is_sandbox'  => $environment->isSandbox(),
            'payment_url' => $result['payment_url'],
            'qr_url'      => route('payment.pay', [
                'merchant' => Str::slug($merchant->business_name),
                'token'    => $transaction->trx_token,
            ]),
            'status'     => $transaction->status?->value ?? TrxStatus::PENDING->value,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'expires_in' => self::CHARGE_MINUTES * 60,
        ]);
    }

    /**
     * Call a counter charge off, so its QR can no longer be paid.
     *
     * Only a charge this merchant made at the counter, and only while it is
     * open. The row is locked and read again inside cancelTransaction, and a
     * payment holds the same lock while it settles, so the two cannot both
     * win: a charge the customer paid a moment earlier comes back paid, and
     * the app shows that rather than a cancel. Asking twice is harmless.
     */
    public function cancelCharge(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->received($request)
            ->where('trx_id', $reference)
            ->where('trx_data->channel', 'counter')
            ->first();

        if ($transaction === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        if ($transaction->status === TrxStatus::PENDING) {
            app(TransactionService::class)->cancelTransaction(
                $transaction->trx_id,
                __('Called off at the counter'),
            );
        }

        return ApiResponse::ok(new MerchantPaymentResource($transaction->refresh()));
    }

    /**
     * Send the customer who paid this the receipt, by email and SMS.
     *
     * Any payment this business received from a DigiKash customer, once it
     * is completed. Only once: asking again answers with the first sending
     * (200, `sent: false`) rather than reaching the customer twice.
     */
    public function sendReceipt(Request $request, string $reference, MerchantReceiptService $receipts): JsonResponse
    {
        $transaction = $this->received($request)->where('trx_id', $reference)->first();

        if ($transaction === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $outcome = $receipts->send($transaction);

        return match ($outcome['refused']) {
            MerchantReceiptService::NOT_PAID   => ApiResponse::error(ApiErrorCode::ValidationFailed, __('Only a completed payment has a receipt.')),
            MerchantReceiptService::NO_PAYER   => ApiResponse::error(ApiErrorCode::ValidationFailed, __('This payment was not made from a customer account, so there is no one to send a receipt to.')),
            MerchantReceiptService::NO_CHANNEL => ApiResponse::error(ApiErrorCode::ValidationFailed, __('A receipt cannot reach this customer right now.')),
            default                            => ApiResponse::ok([...$outcome['receipt'], 'sent' => $outcome['sent']]),
        };
    }

    /**
     * One charge, to see whether the customer has paid yet.
     */
    public function chargeStatus(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->received($request)->where('trx_id', $reference)->first();

        return $transaction === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(new MerchantPaymentResource($transaction));
    }

    public function show(Request $request, string $merchant): JsonResponse
    {
        $record = $this->ownedMerchants($request)
            ->with(['currency', 'supportedCurrencies'])
            ->whereKey($merchant)
            ->first();

        return $record === null
            ? ApiResponse::error(ApiErrorCode::ResourceNotFound)
            : ApiResponse::ok(new MerchantResource($record));
    }

    private function ownedMerchants(Request $request): Builder
    {
        return Merchant::query()->where('user_id', $request->user()->id);
    }

    /**
     * Money that came in to this merchant, whatever its state.
     */
    private function received(Request $request): Builder
    {
        return Transaction::query()
            ->where('user_id', $request->user()->id)
            ->where('trx_type', TrxType::RECEIVE_PAYMENT)
            ->where('amount_flow', AmountFlow::PLUS);
    }
}
