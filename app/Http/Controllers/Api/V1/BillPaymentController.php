<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\BillPayment\BillCustomerLookupStatus;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BillPayment\PayBillRequest;
use App\Http\Requests\Api\V1\BillPayment\QuoteBillRequest;
use App\Http\Requests\Api\V1\BillPayment\VerifyBillCustomerRequest;
use App\Http\Requests\BillPayment\SavedBillReminderRequest;
use App\Http\Resources\Api\V1\BillCategoryResource;
use App\Http\Resources\Api\V1\BillerResource;
use App\Http\Resources\Api\V1\BillPaymentResource;
use App\Http\Resources\Api\V1\SavedBillResource;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillPayment;
use App\Models\Wallet;
use App\Services\BillPayment\BillCustomerVerifier;
use App\Services\BillPayment\BillerDirectory;
use App\Services\BillPayment\BillerRules;
use App\Services\BillPayment\BillPaymentNotifier;
use App\Services\BillPayment\BillPaymentPricer;
use App\Services\BillPayment\BillPaymentService;
use App\Services\BillPayment\BillPaymentSubmission;
use App\Services\BillPayment\SavedBillBook;
use App\Services\Money\WalletPinGuard;
use App\Services\Tax\TaxPresenter;
use App\Services\WalletService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bill payments from the app: the hub, the billers, a quote, paying, and the
 * payments themselves.
 *
 * The same rules as the web pages, read from the same places. BillerDirectory
 * decides which billers and countries a customer sees, BillPaymentPricer
 * prices, and BillPaymentService debits; nothing here computes money. Every
 * route carries `feature.enabled:bill_payment`, so a module that is off, or
 * hidden for the caller's panel, is a 404.
 *
 * A biller is only resolved while it is payable, a wallet only among the
 * caller's own (by uuid), and a payment only through the caller's own
 * relation, so another customer's wallet or payment is a 404 exactly like one
 * that does not exist.
 *
 * Paying is safe to send twice. The route's api.idempotency middleware hands a
 * retry the stored first answer; and because it releases the key on a refusal
 * or a crash, the key is also handed to BillPaymentService as its own,
 * namespaced 'api:'.sha256(key), where the unique index on (user, key) makes
 * sure one key is one payment and one debit whatever the middleware did.
 */
class BillPaymentController extends Controller
{
    /** Recent payments on the hub. */
    private const RECENT_LIMIT = 10;

    /** The most billers one list returns. */
    private const BILLERS_LIMIT = 500;

    private const HISTORY_PER_PAGE = 15;

    private const HISTORY_MAX_PER_PAGE = 50;

    public function __construct(
        private readonly BillerDirectory $directory,
        private readonly SavedBillBook $savedBills,
    ) {}

    /**
     * The hub: the countries that have billers, the categories in the
     * selected one with how many billers each has, the customer's recent
     * payments, and how many are still waiting.
     *
     * `default_country` is the country the categories are for: the one
     * asked for when it has billers, else the customer's own, the home
     * country, or the first with billers.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        $user      = $request->user();
        $countries = $this->directory->payableCountries();
        $country   = $this->directory->defaultCountry($user, $validated['country'] ?? null, $countries);

        $categories = $country === null
            ? collect()
            : BillCategory::query()
                ->active()
                ->whereHas('billers', fn (Builder $billers): Builder => $this->directory->payableIn($billers, $country))
                ->withCount(['billers as billers_count' => fn (Builder $billers): Builder => $this->directory->payableIn($billers, $country)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        $recent = $user->billPayments()
            ->with(BillPaymentResource::RELATIONS)
            ->latest()
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $awaitingCount = $user->billPayments()
            ->whereIn('status', [BillPaymentStatus::Pending->value, BillPaymentStatus::Processing->value])
            ->count();

        return ApiResponse::ok([
            'default_country' => $country,
            'countries'       => $this->directory->countryOptions($countries),
            'categories'      => BillCategoryResource::collection($categories)->resolve($request),
            'recent'          => BillPaymentResource::collection($recent)->resolve($request),
            'saved'           => SavedBillResource::collection($this->savedBills->forUser($user))->resolve($request),
            'awaiting_count'  => $awaitingCount,
        ]);
    }

    /**
     * The payable billers in one country, optionally in one category and
     * matching a name. Without a country, the customer's default one.
     */
    public function billers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country'  => ['nullable', 'string', 'size:2'],
            'category' => ['nullable', 'string', 'max:64'],
            'q'        => ['nullable', 'string', 'max:100'],
        ]);

        $country = isset($validated['country'])
            ? strtoupper($validated['country'])
            : $this->directory->defaultCountry($request->user(), null, $this->directory->payableCountries());

        if ($country === null) {
            return ApiResponse::ok([]);
        }

        $category = $validated['category'] ?? null;
        $search   = trim((string) ($validated['q'] ?? ''));

        $billers = $this->directory->payableIn(Biller::query(), $country)
            ->with(['category', 'provider', 'activeItems'])
            ->when($category !== null, fn (Builder $query): Builder => $query->whereHas(
                'category',
                fn (Builder $categories): Builder => $categories->where('code', $category),
            ))
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::BILLERS_LIMIT)
            ->get();

        return ApiResponse::ok(BillerResource::collection($billers)->resolve($request));
    }

    /**
     * One biller and the customer's wallets that can pay it: active ones in
     * the biller's currency, since a payment is never converted.
     */
    public function biller(Request $request, string $biller): JsonResponse
    {
        $found = Biller::query()
            ->payable()
            ->with(['category', 'provider', 'activeItems'])
            ->findOrFail($biller);

        $currency = strtoupper((string) $found->currency);

        $wallets = $request->user()->activeWallets()
            ->filter(fn (Wallet $wallet): bool => strtoupper((string) $wallet->currency?->code) === $currency)
            ->map(fn (Wallet $wallet): array => [
                'id'       => $wallet->uuid,
                'currency' => $wallet->currency->code,
                'balance'  => ApiMoney::amount((string) $wallet->balance, $wallet->currency->code),
            ])
            ->values()
            ->all();

        return ApiResponse::ok((new BillerResource($found))->resolve($request) + ['wallets' => $wallets]);
    }

    /**
     * What this bill costs from this wallet. Read-only: nothing is written
     * and nothing is reserved. Every figure is the pricer's own string.
     */
    public function quote(QuoteBillRequest $request, BillPaymentPricer $pricer, BillerRules $rules, WalletService $wallets): JsonResponse
    {
        $biller    = $request->biller();
        $validated = $request->validated();

        $wallet = $this->ownWallet($request, (string) $validated['wallet_id']);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $wallets->assertOperational($wallet);

        $item  = $rules->resolveItem($biller, isset($validated['biller_item_id']) ? (int) $validated['biller_item_id'] : null);
        $quote = $pricer->quote($biller, $wallet, $item, $this->submittedAmount($validated['amount'] ?? null));

        return ApiResponse::ok([
            'bill_amount'        => ApiMoney::amount($quote->billAmount, $quote->billCurrency),
            'bill_currency'      => $quote->billCurrency,
            'amount'             => ApiMoney::amount($quote->amount, $quote->currency),
            'fee'                => ApiMoney::amount($quote->fee, $quote->currency),
            'tax'                => TaxPresenter::api($quote->taxQuote()),
            'total'              => ApiMoney::amount($quote->total, $quote->currency),
            'currency'           => $quote->currency,
            'decimals'           => $quote->decimals,
            'balance'            => ApiMoney::amount($quote->balance, $quote->currency),
            'balance_after'      => ApiMoney::amount($quote->balanceAfter, $quote->currency),
            'sufficient_balance' => $quote->sufficientBalance,
            'fee_description'    => $quote->feeDescription,
        ]);
    }

    /**
     * Whether the biller's provider knows this account, and whose it is,
     * asked before the customer pays. Read-only.
     *
     * `{verified: true, customer_name}` when the provider knows it, and
     * `{verified: false, customer_name: null}` when it could not say or the
     * biller is not looked up at all. A number that breaks the biller's rules,
     * or that the provider has no account for, is the ordinary 422 on
     * `customer_number`. The answer is cached, so the payment that follows
     * does not ask the provider again.
     */
    public function verifyCustomer(VerifyBillCustomerRequest $request, BillCustomerVerifier $verifier): JsonResponse
    {
        $validated = $request->validated();

        $lookup = $verifier->verifyEntered(
            $request->biller(),
            isset($validated['biller_item_id']) ? (int) $validated['biller_item_id'] : null,
            (string) $validated['customer_number'],
        );

        if ($lookup?->status === BillCustomerLookupStatus::NotFound) {
            throw ValidationException::withMessages(['customer_number' => __('The provider could not find this account.')]);
        }

        $verified = $lookup?->status === BillCustomerLookupStatus::Verified;

        return ApiResponse::ok([
            'verified'      => $verified,
            'customer_name' => $verified ? $lookup->customerName : null,
        ]);
    }

    /**
     * The customer's payments, newest first, optionally of one status.
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', Rule::enum(BillPaymentStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::HISTORY_MAX_PER_PAGE],
        ]);

        $status = isset($validated['status']) ? BillPaymentStatus::from((string) $validated['status']) : null;

        $payments = $request->user()->billPayments()
            ->with(BillPaymentResource::RELATIONS)
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value))
            ->latest()
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? self::HISTORY_PER_PAGE));

        return ApiResponse::paginated(BillPaymentResource::collection($payments));
    }

    /**
     * One of the customer's payments, for the receipt and for an app polling
     * a payment an operator has not paid yet.
     */
    public function showPayment(Request $request, string $billPayment): JsonResponse
    {
        $payment = $request->user()->billPayments()
            ->where('uuid', $billPayment)
            ->with(BillPaymentResource::RELATIONS)
            ->firstOrFail();

        return ApiResponse::ok((new BillPaymentResource($payment))->resolve($request));
    }

    /**
     * The prepaid token sent to the customer again, by the channels the
     * admin opened and their own preferences allow. Only the payment's
     * owner, only a paid bill that holds a token, only where delivery is on.
     */
    public function resendToken(Request $request, string $billPayment, BillPaymentNotifier $notifier): JsonResponse
    {
        if (! BillPaymentNotifier::tokenDeliveryOn()) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $payment = $request->user()->billPayments()
            ->where('uuid', $billPayment)
            ->where('status', BillPaymentStatus::Completed->value)
            ->with('biller')
            ->first();

        if ($payment === null || ! $notifier->sendToken($payment)) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        return ApiResponse::ok(['sent' => true]);
    }

    /**
     * Pay one bill from one of the customer's wallets.
     *
     * BillPaymentService debits the total and records the payment in one
     * transaction, then hands it to its fulfiller. The answer is the payment
     * as it then stands: 202 while it waits (an operator pays a Manual
     * biller later), 201 when the provider completed it at once, and a 422
     * carrying the payment when the provider refused it and the money went
     * back. A retry that reaches the service is answered the same way from
     * the payment the first attempt recorded, even once its biller is
     * switched off. The wallet PIN is asked for when one is set.
     *
     * The answer never carries the token, even for a completed payment:
     * api.idempotency stores this body in plain text to replay it, and the
     * token lives only in the payment's encrypted column. The app reads it
     * from GET bill-payments/{uuid}.
     */
    public function store(PayBillRequest $request, BillPaymentService $service, WalletPinGuard $pins): JsonResponse
    {
        $validated = $request->validated();
        $user      = $request->user();

        $wallet = $this->ownWallet($request, (string) $validated['wallet_id']);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $pins->assert($user, $validated['wallet_pin'] ?? null, context: ['flow' => 'bill_payment']);

        $submitted = $service->submit($user, new BillPaymentSubmission(
            walletId: (int) $wallet->id,
            billerId: $request->billerId(),
            billerItemId: isset($validated['biller_item_id']) ? (int) $validated['biller_item_id'] : null,
            amount: $this->submittedAmount($validated['amount'] ?? null),
            customerNumber: (string) $validated['customer_number'],
            idempotencyKey: $request->idempotencyKey(),
            expectedTotal: $this->submittedAmount($validated['expected_total'] ?? null),
            channel: BillPaymentSubmission::CHANNEL_API,
        ));

        $this->keepIfAsked($request, $submitted->payment);

        $payment       = $submitted->payment->fresh(BillPaymentResource::RELATIONS) ?? $submitted->payment;
        $body          = (new BillPaymentResource($payment))->resolve($request);
        $body['token'] = null;

        return match ($payment->status) {
            BillPaymentStatus::Completed => ApiResponse::created($body),
            BillPaymentStatus::Failed    => ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                __('The payment did not go through. The amount has been returned to your wallet.'),
                extra: ['data' => ['payment' => $body]],
            ),
            default => ApiResponse::accepted($body),
        };
    }

    /**
     * BP3: remind the caller of one of "My bills" on a day of each month
     * (`remind_day` 1-31), or stop (null). Nothing is paid by itself.
     * Another customer's id is a 404, like one that never was.
     */
    public function setSavedReminder(SavedBillReminderRequest $request, string $savedBill): JsonResponse
    {
        $bill = $this->savedBills->setReminder($request->user(), (int) $savedBill, $request->day());

        if ($bill === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        return ApiResponse::ok(['id' => (int) $bill->id, 'remind_day' => $bill->remind_day]);
    }

    /**
     * Forget one of the caller's "My bills". The payments made to it stay in
     * the history. Another customer's id is a 404, like one that never was.
     */
    public function forgetSaved(Request $request, string $savedBill): JsonResponse
    {
        if (! $this->savedBills->forget($request->user(), (int) $savedBill)) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        return ApiResponse::ok(['id' => (int) $savedBill]);
    }

    /** One of the caller's own wallets by its uuid, or null. */
    private function ownWallet(Request $request, string $uuid): ?Wallet
    {
        return Wallet::query()
            ->where('user_id', $request->user()->id)
            ->where('uuid', $uuid)
            ->with('currency')
            ->first();
    }

    /**
     * Keep the bill in "My bills" when the customer asked to. Saving is a
     * convenience beside a payment that already happened, so a failure here
     * is reported and never turns that payment into an error.
     */
    private function keepIfAsked(Request $request, BillPayment $payment): void
    {
        if (! $request->boolean('save')) {
            return;
        }

        rescue(fn (): mixed => $this->savedBills->keep($request->user(), $payment, $request->validated('save_name')));
    }

    /** A blank amount is no amount; anything else goes to the service as typed. */
    private function submittedAmount(mixed $amount): ?string
    {
        if ($amount === null || (is_string($amount) && trim($amount) === '')) {
            return null;
        }

        return trim((string) $amount);
    }
}
