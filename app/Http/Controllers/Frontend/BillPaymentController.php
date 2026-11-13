<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\BillPayment\BillCustomerLookupStatus;
use App\Enums\BillPayment\BillerAmountType;
use App\Enums\BillPayment\BillPaymentStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BillPayment\PayBillRequest;
use App\Http\Requests\BillPayment\QuoteBillRequest;
use App\Http\Requests\BillPayment\SavedBillReminderRequest;
use App\Http\Requests\BillPayment\VerifyBillCustomerRequest;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillPayment;
use App\Models\SavedBill;
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
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The customer's side of bill payments: browse billers by country and
 * category, price a bill, pay it, and read the receipt.
 *
 * Every route sits behind `feature.enabled:bill_payment`, so a module that is
 * off, or hidden for the customer's panel, answers 404 everywhere.
 *
 * A biller is only ever resolved through Biller::payable(), so a biller whose
 * category or provider was switched off is a 404 rather than a form that
 * fails on submit. A payment is only ever loaded through the signed-in
 * customer's own relation, so another customer's receipt is a 404 too.
 *
 * No money is computed here. BillPaymentPricer prices, BillPaymentService
 * debits, and the page's script only shows the strings the quote returns.
 */
class BillPaymentController extends Controller
{
    /** Recent payments on the hub. */
    private const RECENT_LIMIT = 10;

    private const HISTORY_PER_PAGE = 15;

    /** The history filter for every payment the hub banner counts as not settled yet. */
    public const OPEN_FILTER = 'open';

    /** Statuses the hub banner counts and the open filter lists. */
    private const OPEN_STATUSES = [BillPaymentStatus::Pending->value, BillPaymentStatus::Processing->value];

    public function __construct(
        private readonly BillerDirectory $directory,
        private readonly SavedBillBook $savedBills,
    ) {}

    public function index(Request $request): View
    {
        $user      = $request->user();
        $countries = $this->directory->payableCountries();
        $country   = $this->directory->defaultCountry($user, $this->queryString($request, 'country'), $countries);

        $categories = $country === null
            ? new Collection
            : BillCategory::query()
                ->active()
                ->whereHas('billers', fn (Builder $billers): Builder => $this->directory->payableIn($billers, $country))
                ->withCount(['billers as billers_count' => fn (Builder $billers): Builder => $this->directory->payableIn($billers, $country)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        $recentPayments = $user->billPayments()
            ->with('biller')
            ->latest()
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $openPayments = $user->billPayments()
            ->whereIn('status', self::OPEN_STATUSES);

        $awaitingCount = (clone $openPayments)->count();
        $latestOpen    = $awaitingCount > 0
            ? (clone $openPayments)->with(['biller', 'provider'])->latest()->latest('id')->first()
            : null;

        return view('frontend.user.bill_payment.index', [
            'countries'      => $this->directory->countryOptions($countries),
            'country'        => $country,
            'categories'     => $categories,
            'categoryIcons'  => $this->categoryIcons(),
            'recentPayments' => $recentPayments,
            'savedBills'     => $this->savedBills->forUser($user),
            'awaitingCount'  => $awaitingCount,
            'awaitingNote'   => $latestOpen !== null ? $this->directory->processingNote($latestOpen->biller, $latestOpen) : null,
        ]);
    }

    public function history(Request $request): View
    {
        $statusFilter = $this->queryString($request, 'status');
        $openOnly     = $statusFilter === self::OPEN_FILTER;
        $status       = $openOnly ? null : BillPaymentStatus::tryFrom($statusFilter);
        $category     = $this->queryString($request, 'category');

        $payments = $request->user()->billPayments()
            ->with('biller')
            ->when($openOnly, fn (Builder $query): Builder => $query->whereIn('status', self::OPEN_STATUSES))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value))
            ->when($category !== '', fn (Builder $query): Builder => $query->where('category_code', $category))
            ->latest()
            ->latest('id')
            ->paginate(self::HISTORY_PER_PAGE)
            ->withQueryString();

        $categories = BillCategory::query()->orderBy('sort_order')->orderBy('name')->get(['code', 'name', 'icon']);

        return view('frontend.user.bill_payment.history', [
            'payments'      => $payments,
            'status'        => $status,
            'openOnly'      => $openOnly,
            'category'      => $category,
            'statuses'      => BillPaymentStatus::cases(),
            'categories'    => $categories,
            'categoryIcons' => $categories->pluck('icon', 'code')->all(),
        ]);
    }

    public function billers(Request $request, string $category): View
    {
        $category = BillCategory::query()->active()->where('code', $category)->firstOrFail();

        $countries = $this->directory->payableCountries();
        $country   = $this->directory->defaultCountry($request->user(), $this->queryString($request, 'country'), $countries);

        $billers = $country === null
            ? new Collection
            : $this->directory->payableIn(Biller::query(), $country)
                ->where('bill_category_id', $category->id)
                ->withCount('activeItems')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        return view('frontend.user.bill_payment.billers', [
            'category'    => $category,
            'countries'   => $this->directory->countryOptions($countries),
            'country'     => $country,
            'billers'     => $billers,
            'amountHints' => $billers->mapWithKeys(fn (Biller $biller): array => [$biller->id => $this->amountHint($biller)])->all(),
        ]);
    }

    public function create(Request $request, string $biller, BillPaymentPricer $pricer, BillCustomerVerifier $verifier): View
    {
        $biller = Biller::query()
            ->payable()
            ->with(['provider', 'category', 'activeItems'])
            ->findOrFail($biller);

        $currency = strtoupper((string) $biller->currency);
        $wallets  = $request->user()->activeWallets()
            ->filter(fn (Wallet $wallet): bool => strtoupper((string) $wallet->currency?->code) === $currency)
            ->values();

        [$feeFixed, $feePercent] = $pricer->feeRule($biller, $currency);

        // The key is rendered once per page. A form sent back with errors
        // keeps its key, so a corrected resubmission is still the same
        // attempt; a fresh page load is a fresh attempt.
        $idempotencyKey = $request->old('idempotency_key');

        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            $idempotencyKey = (string) Str::uuid();
        }

        $prefill = $this->prefill($request, $biller);

        return view('frontend.user.bill_payment.create', [
            'biller'         => $biller,
            'prefillNumber'  => $prefill['customer_number'],
            'prefillItemId'  => $prefill['item_id'],
            'savedBill'      => $prefill['saved'],
            'currency'       => $currency,
            'decimals'       => Money::decimals($currency),
            'wallets'        => $wallets,
            'feeDescription' => Money::describeFee($feeFixed, $feePercent, $currency),
            'amountHint'     => $this->amountHint($biller),
            'processingNote' => $this->directory->processingNote($biller),
            'idempotencyKey' => $idempotencyKey,
            'hasWalletPin'   => $request->user()->hasWalletPin(),
            // The account check is offered only where the provider can answer it.
            'verifyUrl' => $verifier->offersLookup($biller) ? route('user.bill-payment.verify-customer', $biller->id) : null,
        ]);
    }

    /**
     * What this bill costs from this wallet. Read-only: nothing is written and
     * nothing is reserved.
     *
     * Every figure is the pricer's own string at the currency's precision. A
     * refusal is a 422 with the field it belongs to, or a message when it
     * belongs to no field (the biller cannot be priced, the wallet is off).
     */
    public function quote(
        QuoteBillRequest $request,
        BillPaymentPricer $pricer,
        BillerRules $rules,
        WalletService $wallets,
    ): JsonResponse {
        $biller    = $request->biller();
        $validated = $request->validated();

        $wallet = Wallet::query()
            ->where('user_id', $request->user()->id)
            ->with('currency')
            ->find((int) $validated['wallet_id']);

        try {
            if ($wallet === null) {
                throw ValidationException::withMessages(['wallet_id' => __('Choose one of your wallets.')]);
            }

            $wallets->assertOperational($wallet);

            $item  = $rules->resolveItem($biller, isset($validated['biller_item_id']) ? (int) $validated['biller_item_id'] : null);
            $quote = $pricer->quote($biller, $wallet, $item, $this->submittedAmount($validated['amount'] ?? null));
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors'  => $exception->errors(),
            ], 422);
        } catch (NotifyErrorException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => []], 422);
        }

        return response()->json([
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
     * Whether the biller's provider knows this account, asked when the
     * customer leaves the account number field. Read-only.
     *
     * 200 `{verified: true, customer_name}` when the provider knows it, 200
     * `{verified: false, customer_name: null}` when it could not say or the
     * biller is not looked up at all, and a 422 on `customer_number` when the
     * number breaks the biller's rules or the provider has no such account.
     * The answer is cached, so the submit that follows does not ask again.
     */
    public function verifyCustomer(VerifyBillCustomerRequest $request, BillCustomerVerifier $verifier): JsonResponse
    {
        $validated = $request->validated();

        try {
            $lookup = $verifier->verifyEntered(
                $request->biller(),
                isset($validated['biller_item_id']) ? (int) $validated['biller_item_id'] : null,
                (string) $validated['customer_number'],
            );

            if ($lookup?->status === BillCustomerLookupStatus::NotFound) {
                throw ValidationException::withMessages(['customer_number' => __('The provider could not find this account.')]);
            }
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors'  => $exception->errors(),
            ], 422);
        }

        $verified = $lookup?->status === BillCustomerLookupStatus::Verified;

        return response()->json([
            'verified'      => $verified,
            'customer_name' => $verified ? $lookup->customerName : null,
        ]);
    }

    public function store(PayBillRequest $request, BillPaymentService $service, WalletPinGuard $pins): RedirectResponse
    {
        $validated = $request->validated();
        $user      = $request->user();

        try {
            $pins->assert($user, $validated['wallet_pin'] ?? null, context: ['flow' => 'bill_payment']);

            $submitted = $service->submit($user, new BillPaymentSubmission(
                walletId: (int) $validated['wallet_id'],
                billerId: $request->billerId(),
                billerItemId: isset($validated['biller_item_id']) ? (int) $validated['biller_item_id'] : null,
                amount: $this->submittedAmount($validated['amount'] ?? null),
                customerNumber: (string) $validated['customer_number'],
                idempotencyKey: $request->idempotencyKey(),
                expectedTotal: $this->submittedAmount($validated['expected_total'] ?? null),
                channel: BillPaymentSubmission::CHANNEL_WEB,
            ));
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except(PayBillRequest::NEVER_FLASHED))
                ->withErrors($exception->errors());
        } catch (IdempotencyConflictException) {
            notifyEvs('error', __('This form was already submitted with different details. Reload the page to start a new payment.'));

            return back()->withInput($request->except(PayBillRequest::NEVER_FLASHED));
        } catch (NotifyErrorException $exception) {
            notifyEvs('error', $exception->getMessage());

            return back()->withInput($request->except(PayBillRequest::NEVER_FLASHED));
        }

        $this->keepIfAsked($request, $submitted->payment);

        notifyEvs('success', $submitted->replayed
            ? __('This payment was already submitted.')
            : __('Bill payment submitted.'));

        return redirect()->route('user.bill-payment.show', $submitted->payment);
    }

    /**
     * The prepaid token sent to the customer again, by the channels the
     * admin opened and their own preferences allow. Only the payment's
     * owner, only a paid bill that holds a token, only where delivery is on.
     */
    public function resendToken(Request $request, string $billPayment, BillPaymentNotifier $notifier): RedirectResponse
    {
        abort_unless(BillPaymentNotifier::tokenDeliveryOn(), 404);

        $payment = $request->user()->billPayments()
            ->where('uuid', $billPayment)
            ->where('status', BillPaymentStatus::Completed->value)
            ->with('biller')
            ->firstOrFail();

        abort_unless($notifier->sendToken($payment), 404);

        notifyEvs('success', __('Sent. Check your email, phone or notifications.'));

        return redirect()->route('user.bill-payment.show', $payment);
    }

    public function show(Request $request, string $billPayment): View
    {
        $payment = $request->user()->billPayments()
            ->where('uuid', $billPayment)
            ->with(['biller.provider', 'provider', 'transaction', 'refundTransaction'])
            ->firstOrFail();

        $isCompleted = $payment->status === BillPaymentStatus::Completed;

        return view('frontend.user.bill_payment.show', [
            'payment' => $payment,
            // Shown to its owner only, and only once the bill is paid. A key
            // the app can no longer decrypt reads as no token rather than a
            // broken receipt.
            'token'          => $isCompleted ? rescue(fn (): ?string => $payment->token, null) : null,
            'tokenLabel'     => trim((string) $payment->biller?->token_label),
            'tokenDelivery'  => BillPaymentNotifier::tokenDeliveryOn(),
            'processingNote' => $this->directory->processingNote($payment->biller, $payment),
            'savedBill'      => $this->savedBills->forPayment($request->user(), $payment),
            // Offered while the biller can still be paid; the pay page reads
            // the number back from this payment, so it never rides the URL.
            'payAgainUrl' => $payment->biller_id !== null && Biller::query()->payable()->whereKey($payment->biller_id)->exists()
                ? route('user.bill-payment.create', ['biller' => $payment->biller_id, 'again' => $payment->uuid])
                : null,
        ]);
    }

    /**
     * BP3: remind the customer of one of "My bills" on a day of each month,
     * or stop. Nothing is paid by itself; another customer's bill is a 404.
     */
    public function setSavedReminder(SavedBillReminderRequest $request, string $savedBill): RedirectResponse
    {
        $day  = $request->day();
        $bill = $this->savedBills->setReminder($request->user(), (int) $savedBill, $day);

        abort_if($bill === null, 404);

        notifyEvs('success', $day === null
            ? __('No more reminders for :name.', ['name' => $bill->title()])
            : __('We will remind you to pay :name on the :day of each month.', ['name' => $bill->title(), 'day' => Carbon::create(2026, 1, $day)?->format('jS')]));

        return redirect()->route('user.bill-payment.index');
    }

    /** Forget one of the customer's "My bills"; another customer's is a 404. */
    public function forgetSaved(Request $request, string $savedBill): RedirectResponse
    {
        abort_unless($this->savedBills->forget($request->user(), (int) $savedBill), 404);

        notifyEvs('success', __('Removed from My bills.'));

        return redirect()->route('user.bill-payment.index');
    }

    /**
     * Each category's icon key by code, so a payment row shows the icon of
     * the category it was paid under (the row keeps the code as a snapshot).
     *
     * @return array<string, string|null>
     */
    private function categoryIcons(): array
    {
        return BillCategory::query()->pluck('icon', 'code')->all();
    }

    /**
     * What a customer can pay this biller: a range for an open amount, or how
     * many packages it offers.
     */
    private function amountHint(Biller $biller): string
    {
        $currency = strtoupper((string) $biller->currency);

        if ($biller->amount_type === BillerAmountType::Fixed) {
            $count = (int) ($biller->active_items_count ?? ($biller->relationLoaded('activeItems')
                ? $biller->activeItems->count()
                : $biller->activeItems()->count()));

            return trans_choice(':count package|:count packages', $count, ['count' => $count]);
        }

        $min = $biller->min_amount !== null ? Money::format($biller->min_amount, $currency, false) : null;
        $max = $biller->max_amount !== null ? Money::format($biller->max_amount, $currency, false) : null;

        return match (true) {
            $min !== null && $max !== null => __(':min – :max :currency', ['min' => $min, 'max' => $max, 'currency' => $currency]),
            $min !== null                  => __('From :min :currency', ['min' => $min, 'currency' => $currency]),
            $max !== null                  => __('Up to :max :currency', ['max' => $max, 'currency' => $currency]),
            default                        => __('Any amount in :currency', ['currency' => $currency]),
        };
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

    /**
     * What the pay page starts filled with: one of "My bills" (?saved=id) or
     * an earlier payment of the customer's (?again=uuid), and only when it is
     * for this biller. A package no longer on sale is left unpicked.
     *
     * @return array{customer_number: ?string, item_id: ?int, saved: ?SavedBill}
     */
    private function prefill(Request $request, Biller $biller): array
    {
        $user  = $request->user();
        $saved = null;
        $item  = null;

        $savedId = $this->queryString($request, 'saved');
        $again   = $this->queryString($request, 'again');

        if (ctype_digit($savedId)) {
            $saved = $this->savedBills->find($user, (int) $savedId);
            $item  = $saved === null ? null : $user->billPayments()
                ->where('biller_id', $saved->biller_id)
                ->where('customer_number', $saved->customer_number)
                ->latest()
                ->latest('id')
                ->value('biller_item_id');
        } elseif (Str::isUuid($again)) {
            $payment = $user->billPayments()->where('uuid', $again)->where('biller_id', $biller->id)->first();

            if ($payment !== null) {
                $item  = $payment->biller_item_id;
                $saved = $this->savedBills->forPayment($user, $payment)
                    ?? new SavedBill(['biller_id' => $biller->id, 'customer_number' => $payment->customer_number]);
            }
        }

        if ($saved === null || (int) $saved->biller_id !== (int) $biller->id) {
            return ['customer_number' => null, 'item_id' => null, 'saved' => null];
        }

        return [
            'customer_number' => (string) $saved->customer_number,
            'item_id'         => $item !== null && $biller->activeItems->contains('id', $item) ? (int) $item : null,
            'saved'           => $saved->exists ? $saved : null,
        ];
    }

    /** A blank amount is no amount; anything else goes to the service as typed. */
    private function submittedAmount(mixed $amount): ?string
    {
        if ($amount === null || (is_string($amount) && trim($amount) === '')) {
            return null;
        }

        return trim((string) $amount);
    }

    /** A query parameter as a trimmed string, or '' when it is missing or not a string. */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }
}
