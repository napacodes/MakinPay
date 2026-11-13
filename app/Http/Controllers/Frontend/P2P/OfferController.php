<?php

namespace App\Http\Controllers\Frontend\P2P;

use App\Constants\CurrencyType;
use App\Enums\P2P\OfferStatus;
use App\Enums\P2P\OrderSide;
use App\Enums\P2P\OrderStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\P2P\StoreOfferRequest;
use App\Http\Requests\P2P\UpdateOfferRequest;
use App\Models\Currency;
use App\Models\P2P\Offer;
use App\Models\P2P\OfferFeedback;
use App\Models\P2P\Order as P2POrder;
use App\Models\P2P\PaymentAccount;
use App\Models\P2P\PaymentMethod as P2PPaymentMethod;
use App\Models\P2P\PromotionPackage;
use App\Models\Wallet;
use App\Services\P2P\P2POfferMarketplace;
use App\Services\P2P\P2POfferPromotionService;
use App\Services\P2P\P2POfferService;
use App\Services\SubscriptionFeatureService;
use App\Support\P2PPaymentMethodManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class OfferController extends Controller
{
    public function __construct(
        protected SubscriptionFeatureService $subscriptionFeatures,
        protected P2POfferMarketplace $marketplace,
        protected P2POfferService $offers,
    ) {}

    // region Marketplace and Trade Ad Screens

    public function index(Request $request): View
    {
        $userCountryCode = P2PPaymentMethodManager::resolveCountryCode(auth()->user());

        $effectiveSide = $this->marketplace->effectiveSide($request->string('side')->toString());

        $offers = $this->marketplace->search(auth()->user(), [
            'side'              => $request->string('side')->toString(),
            'currency'          => $request->string('currency')->toString(),
            'fiat'              => $request->string('fiat')->toString(),
            'payment_method_id' => $request->integer('payment_method_id'),
            'country'           => $request->string('country')->toString(),
            'amount_min'        => $request->input('amount_min'),
            'amount_max'        => $request->input('amount_max'),
            'sort'              => $request->string('sort')->toString(),
        ])->withQueryString();

        $sellerStats = $this->marketplace->traderStats($offers->getCollection()->pluck('user_id'));

        $priceTrends = $this->marketplace->priceTrends($offers->getCollection(), $effectiveSide);

        $currencyOptions = $this->marketplace->assetOptions();

        $fiatOptions = $this->marketplace->fiatOptions();

        $userPaymentAccounts = PaymentAccount::query()
            ->with('paymentMethod')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->get();
        $userPaymentAccounts = P2PPaymentMethodManager::sortPaymentAccounts($userPaymentAccounts, $userCountryCode);

        $savedMethodIds = $userPaymentAccounts
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $methods = P2PPaymentMethodManager::sortMethods(
            P2PPaymentMethod::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'logo', 'country', 'sort_order']),
            $userCountryCode,
            $savedMethodIds
        );

        $countryOptions = P2PPaymentMethodManager::countryOptions($methods);

        $subscriptionGates = $this->resolveP2PSubscriptionGates();

        if ($request->ajax() && $request->boolean('rows')) {
            return view('frontend.user.p2p.trade_ads.partials._trade_ad_rows', compact('offers', 'sellerStats', 'priceTrends', 'userPaymentAccounts', 'subscriptionGates'))
                ->with('marketplaceRow', true);
        }

        $resumeTrade = $this->resolveResumeTrade();

        return view('frontend.user.p2p.trade_ads.marketplace', compact('offers', 'currencyOptions', 'fiatOptions', 'methods', 'countryOptions', 'sellerStats', 'priceTrends', 'userPaymentAccounts', 'subscriptionGates', 'resumeTrade'));
    }

    public function my(Request $request): View
    {
        $userId = (int) auth()->id();

        $search = trim((string) $request->string('search'));
        $status = strtoupper(trim((string) $request->string('status')));
        $sort   = trim((string) $request->string('sort', 'recent'));

        $offers = $this->marketplace->ownOffers($userId, $search, $status, $sort)->withQueryString();

        $summary = $this->marketplace->ownerSummary($userId);

        return view('frontend.user.p2p.trade_ads.my_trade_ads', compact('offers', 'summary', 'search', 'status', 'sort'));
    }

    public function create(): View|RedirectResponse
    {
        if ($redirect = $this->guardP2PAdQuota()) {
            return $redirect;
        }

        $userCountryCode = P2PPaymentMethodManager::resolveCountryCode(auth()->user());
        $wallets         = $this->tradableWallets();
        $paymentAccounts = PaymentAccount::query()
            ->with('paymentMethod')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->get();
        $paymentAccounts = P2PPaymentMethodManager::sortPaymentAccounts($paymentAccounts, $userCountryCode);

        $savedMethodIds = $paymentAccounts
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $methods = P2PPaymentMethodManager::sortMethods(
            P2PPaymentMethod::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            $userCountryCode,
            $savedMethodIds
        );
        $packagesQuery = PromotionPackage::query()->where('status', true);
        if (Schema::hasColumn('p2p_promotion_packages', 'visibility')) {
            $packagesQuery->where('visibility', 'PUBLIC');
        }
        $packages = $packagesQuery
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        ['currencies' => $fiatCurrencies, 'default_id' => $defaultQuoteCurrencyId] = $this->fiatQuoteCurrencies();

        return view('frontend.user.p2p.trade_ads.create_trade_ad', compact('wallets', 'methods', 'packages', 'paymentAccounts', 'userCountryCode', 'fiatCurrencies', 'defaultQuoteCurrencyId'));
    }

    public function edit(Offer $offer): View|RedirectResponse
    {
        $this->authorize('update', $offer);

        if (! $this->offerIsOperational($offer)) {
            notifyEvs('error', __('This trade ad uses a currency that is no longer available. Create a new ad with an active currency.'));

            return redirect()->route('user.p2p.offers.my');
        }

        $hasOpenOrders = $this->offerHasActiveOrders($offer);

        $userCountryCode = P2PPaymentMethodManager::resolveCountryCode(auth()->user());
        $wallets         = $this->tradableWallets();
        $paymentAccounts = PaymentAccount::query()
            ->with('paymentMethod')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->get();
        $paymentAccounts = P2PPaymentMethodManager::sortPaymentAccounts($paymentAccounts, $userCountryCode);

        $savedMethodIds = $paymentAccounts
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $methods = P2PPaymentMethodManager::sortMethods(
            P2PPaymentMethod::query()
                ->where('status', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            $userCountryCode,
            $savedMethodIds
        );

        $packagesQuery = PromotionPackage::query()->where('status', true);
        if (Schema::hasColumn('p2p_promotion_packages', 'visibility')) {
            $packagesQuery->where('visibility', 'PUBLIC');
        }
        $packages = $packagesQuery
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        ['currencies' => $fiatCurrencies, 'default_id' => $defaultQuoteCurrencyId] = $this->fiatQuoteCurrencies();

        $offer->loadMissing(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'promotion.package']);
        $offer->loadCount([
            'orders as total_orders_count',
            'orders as active_orders_count' => function ($q) {
                $q->whereIn('status', [
                    OrderStatus::PENDING->value,
                    OrderStatus::PAID->value,
                    OrderStatus::DISPUTED->value,
                ]);
            },
            'orders as completed_orders_count' => function ($q) {
                $q->where('status', OrderStatus::COMPLETED->value);
            },
            'orders as disputed_orders_count' => function ($q) {
                $q->where('status', OrderStatus::DISPUTED->value);
            },
        ]);

        $totalOrders        = (int) ($offer->total_orders_count ?? 0);
        $openOrders         = (int) ($offer->active_orders_count ?? 0);
        $completedOrders    = (int) ($offer->completed_orders_count ?? 0);
        $disputedOrders     = (int) ($offer->disputed_orders_count ?? 0);
        $completionRate     = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
        $canManageLifecycle = $offer->status !== OfferStatus::DISABLED
            && $openOrders === 0;
        $canEditTradeAd = ! $hasOpenOrders
            && $offer->status !== OfferStatus::DISABLED;

        return view('frontend.user.p2p.trade_ads.create_trade_ad', compact(
            'wallets',
            'methods',
            'packages',
            'paymentAccounts',
            'userCountryCode',
            'fiatCurrencies',
            'defaultQuoteCurrencyId',
            'offer',
            'totalOrders',
            'openOrders',
            'completedOrders',
            'disputedOrders',
            'completionRate',
            'canManageLifecycle',
            'canEditTradeAd'
        ));
    }

    // endregion

    // region Trade Ad Submission and Lifecycle Actions

    public function store(StoreOfferRequest $request, P2POfferPromotionService $promotionService): RedirectResponse
    {
        if ($redirect = $this->guardP2PAdQuota()) {
            return $redirect;
        }

        $validated = $request->validated();

        try {
            $offer = $this->offers->create(auth()->user(), $validated);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back()->withInput();
        }

        $shouldPromote = (bool) ($validated['promote_now'] ?? false);

        if ($shouldPromote) {
            $packageId         = (int) ($validated['promotion_package_id'] ?? 0);
            $promotionWalletId = (int) ($validated['promotion_wallet_id'] ?? 0);

            $package = PromotionPackage::query()
                ->where('id', $packageId)
                ->where('status', true)
                ->when(Schema::hasColumn('p2p_promotion_packages', 'visibility'), function ($q) {
                    $q->where('visibility', 'PUBLIC');
                })
                ->firstOrFail();

            $promotionWallet = Wallet::query()
                ->where('id', $promotionWalletId)
                ->where('user_id', auth()->id())
                ->where('status', true)
                ->with('currency')
                ->firstOrFail();

            try {
                $promotionService->purchase($offer, $package, $promotionWallet, (int) auth()->id());
            } catch (NotifyErrorException $e) {
                notifyEvs('error', $e->getMessage());

                return redirect()
                    ->route('user.p2p.offers.promotion.show', $offer)
                    ->withInput([
                        'package_id' => $packageId,
                        'wallet_id'  => $promotionWalletId,
                    ]);
            }

            notifyEvs('success', __('Trade ad created and promoted successfully'));

            return redirect()->route('user.p2p.offers.my');
        }

        notifyEvs('success', __('Trade ad created successfully'));

        return redirect()->route('user.p2p.offers.my');
    }

    public function update(UpdateOfferRequest $request, Offer $offer): RedirectResponse
    {
        $this->authorize('update', $offer);

        if ($offer->status === OfferStatus::DISABLED) {
            notifyEvs('error', __('This trade ad cannot be updated.'));

            return back();
        }

        if ($this->offerHasActiveOrders($offer)) {
            notifyEvs('error', __('This trade ad has active orders and cannot be edited right now.'));

            return redirect()->route('user.p2p.offers.my');
        }

        $validated        = $request->validated();
        $side             = OrderSide::from($validated['side']);
        $paymentMethodIds = collect($validated['payment_method_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($paymentMethodIds->isEmpty()) {
            notifyEvs('error', $side === OrderSide::SELL
                ? __('Sell trade ads must include at least one accepted payment method.')
                : __('Buy trade ads must include at least one preferred payment method.')
            );

            return back()->withInput();
        }

        $savedMethodIds = PaymentAccount::query()
            ->where('user_id', auth()->id())
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id);

        $missingMethodIds = $paymentMethodIds->diff($savedMethodIds)->values();

        if ($missingMethodIds->isNotEmpty()) {
            notifyEvs('error', $side === OrderSide::SELL
                ? __('Add your saved payment account details for each selected payment method before updating a sell ad.')
                : __('Add your saved payment account details for each preferred payment method before updating a buy ad.')
            );

            return back()->withInput();
        }

        $wallet = $this->resolveTradableWallet((int) $validated['wallet_id']);

        if (! $wallet) {
            notifyEvs('error', __('P2P trade ads support crypto wallets only. Choose an active crypto wallet to continue.'));

            return back()->withInput();
        }

        $offer->update([
            'wallet_id'         => $wallet->id,
            'quote_currency_id' => (int) $validated['quote_currency_id'],
            'side'              => $side,
            'price'             => (float) $validated['price'],
            // The web form sets a price, not a margin. An ad the app made
            // floating would otherwise keep floating, and p2p:reprice would
            // overwrite the price typed here within five minutes.
            'price_type'             => Offer::PRICE_FIXED,
            'margin_pct'             => null,
            'min_amount'             => (float) $validated['min_amount'],
            'max_amount'             => isset($validated['max_amount']) && $validated['max_amount'] !== null ? (float) $validated['max_amount'] : null,
            'payment_window_minutes' => (int) ($validated['payment_window_minutes'] ?? (int) setting('p2p_order_expiry_minutes', 45)),
            'terms'                  => $validated['terms'] ?? null,
        ]);

        $offer->paymentMethods()->sync($paymentMethodIds->all());

        notifyEvs('success', __('Trade ad updated successfully'));

        return redirect()->route('user.p2p.offers.my');
    }

    public function show(Offer $offer): View|RedirectResponse
    {
        $offer->load(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'user.kycSubmission', 'promotion.package'])
            ->loadCount([
                'orders as total_orders_count',
                'orders as open_orders_count' => function ($q) {
                    $q->whereIn('status', [
                        OrderStatus::PENDING->value,
                        OrderStatus::PAID->value,
                        OrderStatus::DISPUTED->value,
                    ]);
                },
                'orders as completed_orders_count' => function ($q) {
                    $q->where('status', OrderStatus::COMPLETED->value);
                },
                'orders as disputed_orders_count' => function ($q) {
                    $q->where('status', OrderStatus::DISPUTED->value);
                },
            ]);

        $userPaymentAccounts = PaymentAccount::query()
            ->with('paymentMethod')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->get();
        $userPaymentAccounts = P2PPaymentMethodManager::sortPaymentAccounts(
            $userPaymentAccounts,
            P2PPaymentMethodManager::resolveCountryCode(auth()->user())
        );

        $this->authorize('view', $offer);

        $isOwner       = (int) $offer->user_id === (int) auth()->id();
        $isOperational = $this->offerIsOperational($offer);

        if (! $isOwner && ! $isOperational) {
            notifyEvs('error', __('This trade ad is no longer available.'));

            return redirect()->route('user.p2p.offers.index');
        }

        $totalOrders        = (int) ($offer->total_orders_count ?? 0);
        $openOrders         = (int) ($offer->open_orders_count ?? 0);
        $completedOrders    = (int) ($offer->completed_orders_count ?? 0);
        $disputedOrders     = (int) ($offer->disputed_orders_count ?? 0);
        $completionRate     = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
        $canManageLifecycle = $isOwner
            && $isOperational
            && $offer->status !== OfferStatus::DISABLED
            && $openOrders === 0;

        $feedbacks = OfferFeedback::query()
            ->with('user')
            ->where('offer_id', $offer->id)
            ->latest()
            ->limit(10)
            ->get();
        $positiveCnt = OfferFeedback::where('offer_id', $offer->id)->where('rating', '>=', 4)->count();
        $negativeCnt = OfferFeedback::where('offer_id', $offer->id)->where('rating', '<=', 2)->count();

        return view('frontend.user.p2p.trade_ads.trade_ad_details', compact(
            'offer',
            'completionRate',
            'totalOrders',
            'openOrders',
            'completedOrders',
            'disputedOrders',
            'feedbacks',
            'positiveCnt',
            'negativeCnt',
            'userPaymentAccounts',
            'isOwner',
            'isOperational',
            'canManageLifecycle'
        ));
    }

    public function toggle(Request $request, Offer $offer): RedirectResponse
    {
        $this->authorize('update', $offer);

        try {
            $this->offers->toggle($offer);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back();
        }

        notifyEvs('success', __('Trade ad status updated.'));

        return back();
    }

    public function destroy(Offer $offer): RedirectResponse
    {
        $this->authorize('delete', $offer);

        try {
            $this->offers->delete($offer);
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back();
        }

        notifyEvs('success', __('Trade ad deleted.'));

        return back();
    }

    /**
     * The user's active crypto wallets eligible to back a P2P trade ad. Fiat
     * wallets are excluded: a P2P offer trades a crypto asset for a fiat price.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Wallet>
     */
    private function tradableWallets(): \Illuminate\Database\Eloquent\Collection
    {
        return Wallet::query()
            ->where('user_id', auth()->id())
            ->active()
            ->whereHas('currency', fn ($q) => $q->where('type', CurrencyType::CRYPTO))
            ->with('currency')
            ->get();
    }

    /**
     * Resolve a wallet the authenticated user may publish an offer against,
     * enforcing ownership, active status, and a crypto currency.
     */
    private function resolveTradableWallet(int $walletId): ?Wallet
    {
        return Wallet::query()
            ->with('currency')
            ->where('id', $walletId)
            ->where('user_id', auth()->id())
            ->active()
            ->whereHas('currency', fn ($q) => $q->where('type', CurrencyType::CRYPTO))
            ->first();
    }

    /**
     * Active fiat currencies selectable as the offer price (quote) currency,
     * plus the default selection for the create form.
     *
     * @return array{currencies: Collection<int, Currency>, default_id: int|null}
     */
    private function fiatQuoteCurrencies(): array
    {
        $currencies = Currency::query()
            ->where('type', CurrencyType::FIAT)
            ->where('status', true)
            ->orderByDesc('default')
            ->orderBy('code')
            ->get(['id', 'code', 'symbol', 'name', 'default']);

        $defaultId = $currencies->firstWhere('default', true)?->id ?? $currencies->first()?->id;

        return ['currencies' => $currencies, 'default_id' => $defaultId !== null ? (int) $defaultId : null];
    }

    private function offerIsOperational(Offer $offer): bool
    {
        return $this->marketplace->isOperational($offer);
    }

    private function offerHasActiveOrders(Offer $offer): bool
    {
        return P2POrder::query()
            ->where('offer_id', $offer->id)
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::PAID->value,
                OrderStatus::DISPUTED->value,
            ])
            ->exists();
    }

    /**
     * @return array{user_id: int, id: int, url: string, status: string, status_label: string, side_value: string, side_label: string, amount: float, currency: string, price: float, fiat_currency: string, payment_window_minutes: int, updated_at: int, updated_label: string}|null
     */
    private function resolveResumeTrade(): ?array
    {
        $userId = (int) auth()->id();

        $order = P2POrder::query()
            ->with(['offer.quoteCurrency', 'wallet.currency'])
            ->where(function ($query) use ($userId) {
                $query->where('maker_id', $userId)
                    ->orWhere('taker_id', $userId);
            })
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::PAID->value,
                OrderStatus::DISPUTED->value,
            ])
            ->where(function ($query) {
                $query->where('status', '!=', OrderStatus::PENDING->value)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if (! $order) {
            return null;
        }

        $updatedAt = $order->updated_at ?? $order->created_at ?? now();

        return [
            'user_id'                => $userId,
            'id'                     => (int) $order->id,
            'url'                    => route('user.p2p.orders.show', $order),
            'status'                 => $order->status->value,
            'status_label'           => $order->status->label(),
            'side_value'             => $order->offer->side->value,
            'side_label'             => $order->offer->side->label(),
            'amount'                 => (float) $order->amount,
            'currency'               => (string) $order->wallet->currency->code,
            'price'                  => (float) $order->price,
            'fiat_currency'          => (string) $order->offer->quote_currency_code,
            'payment_window_minutes' => (int) ($order->offer->payment_window_minutes ?? 0),
            'updated_at'             => (int) $updatedAt->getTimestampMs(),
            'updated_label'          => __('Updated: :time', ['time' => $updatedAt->diffForHumans()]),
        ];
    }

    /**
     * Compute the user's subscription-plan gates for the P2P marketplace UI.
     * The view uses these flags to swap real Buy/Sell/Create flows for the
     * upgrade modal when the user has no quota on their plan.
     *
     * @return array{can_trade: bool, can_create_ad: bool, trade_limit: ?int, ad_limit: ?int}
     */
    private function resolveP2PSubscriptionGates(): array
    {
        return $this->marketplace->subscriptionGates(auth()->user());
    }

    /**
     * Enforce the subscription-plan p2p_ad_limit quota for create / store actions.
     * Returns a redirect when the user has no quota or has hit their cap, null
     * when they are allowed to proceed.
     */
    private function guardP2PAdQuota(): ?RedirectResponse
    {
        $refusal = $this->offers->adQuotaRefusal(auth()->user());

        if ($refusal === null) {
            return null;
        }

        notifyEvs('warning', $refusal);

        return redirect()->route('user.subscription.plans');
    }
}
