<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Constants\CurrencyType;
use App\Enums\Api\ApiErrorCode;
use App\Enums\P2P\OfferStatus;
use App\Enums\P2P\OrderSide;
use App\Enums\P2P\OrderStatus;
use App\Enums\P2P\PromotionStatus;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\P2P\Block;
use App\Models\P2P\Offer;
use App\Models\P2P\OfferFeedback;
use App\Models\P2P\PaymentMethod;
use App\Models\User;
use App\Services\P2P\P2POfferMarketplace;
use App\Services\P2P\P2POfferService;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P2P trade ads, read from the app: the marketplace, the customer's own ads,
 * one ad in full, the profile of the advertiser behind an ad - and the
 * advertiser's own publishing, pausing and withdrawing of ads.
 *
 * The marketplace is the website's own feed (P2POfferMarketplace): never the
 * customer's own ads, never an ad whose wallet, asset or fiat is switched
 * off. An ad that is not active, or no longer tradeable, is shown only to
 * the advertiser; anyone else is told it does not exist, where the website
 * redirects them back to the marketplace.
 */
class P2POfferController extends Controller
{
    private const MARKET_SORTS = ['price', 'price_asc', 'price_desc', 'completion', 'trades', 'release'];

    private const OWN_SORTS = ['recent', 'oldest', 'price_low', 'price_high', 'most_orders'];

    /** What earns the "Top trader" mark: this many completed trades at this completion rate. */
    public const TOP_TRADER_ORDERS = 100;

    public const TOP_TRADER_RATE = 98.0;

    /** The quick tags a review may carry; anything else is refused. */
    public const REVIEW_TAGS = ['fast_release', 'clear_communication', 'would_trade_again', 'slow', 'unresponsive'];

    public function __construct(private readonly P2POfferMarketplace $marketplace) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'side'              => ['sometimes', Rule::enum(OrderSide::class)],
            'currency'          => ['sometimes', 'nullable', 'string', 'max:20'],
            'fiat'              => ['sometimes', 'nullable', 'string', 'max:20'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer'],
            'country'           => ['sometimes', 'nullable', 'string', 'size:2'],
            'amount_min'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'amount_max'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'amount'            => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'verified'          => ['sometimes', 'nullable', 'boolean'],
            'online'            => ['sometimes', 'nullable', 'boolean'],
            'sort'              => ['sometimes', Rule::in(self::MARKET_SORTS)],
        ]);

        $user = $request->user();
        $side = $this->marketplace->effectiveSide($validated['side'] ?? null);

        $page   = $this->marketplace->search($user, $validated);
        $offers = $page->getCollection();
        $stats  = $this->marketplace->traderStats($offers->pluck('user_id'));
        $trends = $this->marketplace->priceTrends($offers, $side);

        $page->through(fn (Offer $offer): array => $this->offer($offer) + [
            'open_orders' => (int) $offer->active_orders_count,
            'advertiser'  => $this->advertiser($offer, $stats[(int) $offer->user_id] ?? null),
            'price_trend' => $trends[(int) $offer->id] ?? null,
        ]);

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $payload['meta'] += [
            'side'    => $side->value,
            'filters' => [
                'assets'          => $this->marketplace->assetOptions(),
                'fiats'           => $this->marketplace->fiatOptions(),
                'payment_methods' => PaymentMethod::query()
                    ->where('status', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'logo', 'country'])
                    ->map(fn (PaymentMethod $method): array => $this->paymentMethod($method))
                    ->values(),
            ],
            'gates' => $this->marketplace->subscriptionGates($user),
        ];

        return $response->setData($payload);
    }

    public function my(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([...array_column(OfferStatus::cases(), 'value'), 'PROMOTED'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort'   => ['sometimes', Rule::in(self::OWN_SORTS)],
        ]);

        $userId = (int) $request->user()->id;

        $page = $this->marketplace->ownOffers(
            $userId,
            trim((string) ($validated['search'] ?? '')),
            (string) ($validated['status'] ?? ''),
            (string) ($validated['sort'] ?? 'recent'),
        );

        $page->through(fn (Offer $offer): array => $this->offer($offer) + [
            'orders' => [
                'total'     => (int) $offer->total_orders_count,
                'open'      => (int) $offer->active_orders_count,
                'completed' => (int) $offer->completed_orders_count,
                'disputed'  => (int) $offer->disputed_orders_count,
            ],
        ]);

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $payload['meta']['summary'] = $this->marketplace->ownerSummary($userId);

        return $response->setData($payload);
    }

    public function show(Request $request, string $offer): JsonResponse
    {
        $user  = $request->user();
        $offer = Offer::query()
            ->with(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'user.kycSubmission', 'promotion.package'])
            ->withCount([
                'orders as total_orders_count',
                'orders as open_orders_count' => fn ($q) => $q->whereIn('status', [
                    OrderStatus::PENDING->value,
                    OrderStatus::PAID->value,
                    OrderStatus::DISPUTED->value,
                ]),
                'orders as completed_orders_count' => fn ($q) => $q->where('status', OrderStatus::COMPLETED->value),
                'orders as disputed_orders_count'  => fn ($q) => $q->where('status', OrderStatus::DISPUTED->value),
            ])
            ->findOrFail($offer);

        $isOwner       = (int) $offer->user_id === (int) $user->id;
        $isOperational = $this->marketplace->isOperational($offer);

        // The policy lets anyone see an active ad and only its owner see the
        // rest; the website also sends everyone else away from one that can
        // no longer be traded.
        abort_unless($user->can('view', $offer) && ($isOwner || $isOperational), 404);

        $total     = (int) $offer->total_orders_count;
        $open      = (int) $offer->open_orders_count;
        $completed = (int) $offer->completed_orders_count;

        $feedback = OfferFeedback::query()
            ->with('user:id,first_name,last_name')
            ->where('offer_id', $offer->id)
            ->latest()
            ->latest('id')
            ->limit(10)
            ->get();

        return ApiResponse::ok($this->offer($offer) + [
            'is_owner'       => $isOwner,
            'is_operational' => $isOperational,
            'can_manage'     => $isOwner && $isOperational && $offer->status !== OfferStatus::DISABLED && $open === 0,
            'orders'         => [
                'total'           => $total,
                'open'            => $open,
                'completed'       => $completed,
                'disputed'        => (int) $offer->disputed_orders_count,
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            ],
            'advertiser' => $this->advertiser($offer, $this->marketplace->traderStats(collect([$offer->user_id]))[(int) $offer->user_id] ?? null),
            'feedback'   => [
                'positive' => OfferFeedback::query()->where('offer_id', $offer->id)->where('rating', '>=', 4)->count(),
                'negative' => OfferFeedback::query()->where('offer_id', $offer->id)->where('rating', '<=', 2)->count(),
                'latest'   => $feedback->map(fn (OfferFeedback $item): array => [
                    'rating'     => (int) $item->rating,
                    'comment'    => $item->comment,
                    'by'         => $item->user?->name,
                    'created_at' => $item->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * An advertiser's profile: who they are, their record as a maker, their
     * tradeable ads and the latest reviews of them.
     *
     * Only someone who has advertised has a profile. The website answers for
     * any account id; the app is only ever sent here from an ad.
     */
    public function profile(Request $request, string $user): JsonResponse
    {
        $advertiser = User::query()
            ->with('kycSubmission')
            ->whereIn('id', Offer::query()->select('user_id'))
            ->findOrFail($user);

        $stats = $this->marketplace->traderStats(collect([$advertiser->id]))[(int) $advertiser->id] ?? null;

        // Tradeable ads only - the feed's rule. The website lists every
        // active ad here, one on a switched-off wallet included.
        $page = Offer::query()
            ->with(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'promotion.package'])
            ->where('user_id', $advertiser->id)
            ->where('status', OfferStatus::ACTIVE)
            ->whereHas('wallet', fn ($query) => $query->active())
            ->whereHas('quoteCurrency', fn ($query) => $query->active())
            ->orderBy('price')
            ->orderBy('id')
            ->paginate(15);

        // Each ad carries its advertiser, so a card on the profile reads as it does on the market.
        $page->through(fn (Offer $offer): array => $this->offer($offer) + ['advertiser' => $this->person($advertiser, $stats)]);

        $feedback = OfferFeedback::query()
            ->select('p2p_offer_feedback.*')
            ->join('p2p_offers', 'p2p_offers.id', '=', 'p2p_offer_feedback.offer_id')
            ->with('user:id,first_name,last_name')
            ->where('p2p_offers.user_id', $advertiser->id)
            ->latest('p2p_offer_feedback.id')
            ->limit(10)
            ->get();

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $reviews = OfferFeedback::query()
            ->join('p2p_offers', 'p2p_offers.id', '=', 'p2p_offer_feedback.offer_id')
            ->where('p2p_offers.user_id', $advertiser->id)
            ->get(['p2p_offer_feedback.rating', 'p2p_offer_feedback.tags']);
        $histogram = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $tagCounts = [];
        foreach ($reviews as $review) {
            $histogram[(int) $review->rating] = ($histogram[(int) $review->rating] ?? 0) + 1;
            foreach ((array) ($review->tags ?? []) as $tag) {
                $tagCounts[(string) $tag] = ($tagCounts[(string) $tag] ?? 0) + 1;
            }
        }
        arsort($tagCounts);

        $payload['meta']['advertiser'] = $this->person($advertiser, $stats) + [
            'joined_at'        => $advertiser->created_at?->toIso8601String(),
            'location'         => trim(implode(', ', array_filter([(string) $advertiser->city, (string) $advertiser->country]))) ?: null,
            'top_trader'       => (int) ($stats['completed_orders'] ?? 0) >= self::TOP_TRADER_ORDERS && (float) ($stats['completion_rate'] ?? 0) >= self::TOP_TRADER_RATE,
            'is_me'            => (int) $advertiser->id === (int) $request->user()->id,
            'blocked'          => Block::query()->where('user_id', $request->user()->id)->where('blocked_user_id', $advertiser->id)->exists(),
            'rating_histogram' => (object) $histogram,
            'tags'             => collect($tagCounts)->map(fn (int $count, string $tag): array => ['tag' => $tag, 'count' => $count])->values(),
            'feedback_count'   => OfferFeedback::query()
                ->join('p2p_offers', 'p2p_offers.id', '=', 'p2p_offer_feedback.offer_id')
                ->where('p2p_offers.user_id', $advertiser->id)
                ->count(),
            'latest_feedback' => $feedback->map(fn (OfferFeedback $item): array => [
                'rating'     => (int) $item->rating,
                'comment'    => $item->comment,
                'by'         => $item->user?->name,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->values(),
        ];

        return $response->setData($payload);
    }

    /**
     * Publish a trade ad, through P2POfferService - the website's own rules:
     * a crypto wallet of the customer's, an active fiat to price it in, and
     * only payment methods they have saved an account for. The plan's ad
     * quota answers 403 FEATURE_NOT_ACCESSIBLE, which the app can take to
     * the plans screen.
     */
    public function store(Request $request, P2POfferService $offers): JsonResponse
    {
        $user = $request->user();

        if (($refusal = $offers->adQuotaRefusal($user)) !== null) {
            return ApiResponse::error(ApiErrorCode::FeatureNotAccessible, $refusal);
        }

        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid) ? $user->wallets()->with('currency')->where('uuid', $uuid)->first() : null;
        $fiat   = is_string($request->input('fiat'))
            ? Currency::query()->where('code', $request->input('fiat'))->where('type', CurrencyType::FIAT)->where('status', true)->first()
            : null;

        $asset = $wallet?->currency?->code;

        $validated = $request->validate([
            'side'      => ['required', Rule::enum(OrderSide::class)],
            'wallet_id' => ['required', 'string'],
            'fiat'      => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($fiat): void {
                if ($fiat === null) {
                    $fail(__('Choose an active fiat currency to price the ad in.'));
                }
            }],
            'price'                  => AmountEntry::rules($fiat?->code ?? siteCurrency()),
            'price_type'             => ['sometimes', Rule::in([Offer::PRICE_FIXED, Offer::PRICE_FLOAT])],
            'margin_pct'             => ['sometimes', 'nullable', 'numeric', 'min:-50', 'max:50'],
            'min_amount'             => AmountEntry::rules($asset ?? siteCurrency()),
            'max_amount'             => [...AmountEntry::rules($asset ?? siteCurrency(), required: false), 'gte:min_amount'],
            'payment_method_ids'     => ['required', 'array', 'min:1'],
            'payment_method_ids.*'   => ['integer', 'exists:p2p_payment_methods,id'],
            'terms'                  => ['nullable', 'string', 'max:2000'],
            'payment_window_minutes' => ['nullable', 'integer', 'min:5', 'max:2880'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $offer = $offers->create($user, [
            'side'                   => $validated['side'],
            'wallet_id'              => $wallet->id,
            'quote_currency_id'      => $fiat->id,
            'price'                  => $validated['price'],
            'price_type'             => $validated['price_type'] ?? Offer::PRICE_FIXED,
            'margin_pct'             => $validated['margin_pct'] ?? null,
            'min_amount'             => $validated['min_amount'],
            'max_amount'             => $validated['max_amount'] ?? null,
            'payment_method_ids'     => $validated['payment_method_ids'],
            'terms'                  => $validated['terms']                  ?? null,
            'payment_window_minutes' => $validated['payment_window_minutes'] ?? null,
        ]);

        return ApiResponse::created($this->offer($offer->load(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'promotion'])));
    }

    /**
     * Change an ad - price, how it is priced, limits, window, terms, methods.
     * A floating ad ignores a typed price and takes the market's.
     */
    public function update(Request $request, string $offer, P2POfferService $offers): JsonResponse
    {
        $found = $this->ownOffer($request, $offer)->load(['wallet.currency', 'quoteCurrency']);
        $asset = $found->wallet?->currency?->code ?? siteCurrency();
        $fiat  = $found->quoteCurrency?->code     ?? siteCurrency();

        $validated = $request->validate([
            'price'      => AmountEntry::rules($fiat, required: false),
            'price_type' => ['sometimes', Rule::in([Offer::PRICE_FIXED, Offer::PRICE_FLOAT])],
            'margin_pct' => ['sometimes', 'nullable', 'numeric', 'min:-50', 'max:50'],
            'min_amount' => AmountEntry::rules($asset, required: false),
            'max_amount' => [...AmountEntry::rules($asset, required: false), function (string $attribute, mixed $value, \Closure $fail) use ($request, $found): void {
                $min = $request->input('min_amount', (string) $found->min_amount);
                if (is_numeric($value) && is_numeric($min) && (float) $value < (float) $min) {
                    $fail(__('The maximum must not be below the minimum.'));
                }
            }],
            'payment_method_ids'     => ['sometimes', 'array', 'min:1'],
            'payment_method_ids.*'   => ['integer', 'exists:p2p_payment_methods,id'],
            'terms'                  => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payment_window_minutes' => ['sometimes', 'integer', 'min:5', 'max:2880'],
        ]);

        $updated = $offers->update($request->user(), $found, $validated);

        return ApiResponse::ok($this->offer($updated->load(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'promotion'])));
    }

    /**
     * The advertiser's last thirty days, for the top of My ads.
     */
    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->marketplace->ownerStats((int) $request->user()->id));
    }

    /**
     * Hide a trader's ads from this customer's market. Their own account,
     * and the trader, are untouched.
     */
    public function block(Request $request, string $user): JsonResponse
    {
        $trader = User::query()->findOrFail($user);
        if ((int) $trader->id === (int) $request->user()->id) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('You cannot block yourself.'));
        }
        Block::query()->firstOrCreate(['user_id' => $request->user()->id, 'blocked_user_id' => $trader->id]);

        return ApiResponse::ok(['blocked' => true]);
    }

    public function unblock(Request $request, string $user): JsonResponse
    {
        Block::query()->where('user_id', $request->user()->id)->where('blocked_user_id', $user)->delete();

        return ApiResponse::ok(['blocked' => false]);
    }

    /**
     * Pause an active ad, or resume a paused one. Only the advertiser's own.
     */
    public function toggle(Request $request, string $offer, P2POfferService $offers): JsonResponse
    {
        $offer = $offers->toggle($this->ownOffer($request, $offer));

        return ApiResponse::ok($this->offer($offer->load(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'promotion'])));
    }

    public function destroy(Request $request, string $offer, P2POfferService $offers): JsonResponse
    {
        $offers->delete($this->ownOffer($request, $offer));

        return ApiResponse::ok(['deleted' => true]);
    }

    private function ownOffer(Request $request, string $offer): Offer
    {
        return Offer::query()->where('user_id', $request->user()->id)->findOrFail($offer);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(Offer $offer): array
    {
        $asset     = $offer->wallet?->currency?->code;
        $fiat      = $offer->quoteCurrency?->code;
        $promotion = $offer->promotion;

        return [
            'id'                     => $offer->id,
            'side'                   => $offer->side->value,
            'status'                 => $offer->status->value,
            'asset'                  => $asset,
            'fiat'                   => $fiat,
            'price'                  => ApiMoney::amount((string) $offer->price, $fiat),
            'min_amount'             => ApiMoney::amount((string) $offer->min_amount, $asset),
            'max_amount'             => $offer->max_amount !== null ? ApiMoney::amount((string) $offer->max_amount, $asset) : null,
            'payment_window_minutes' => (int) $offer->payment_window_minutes,
            'terms'                  => $offer->terms_text,
            'price_type'             => $offer->price_type ?? Offer::PRICE_FIXED,
            'margin_pct'             => $offer->margin_pct === null ? null : (string) $offer->margin_pct,
            'promoted'               => $promotion !== null
                                                     && $promotion->status               === PromotionStatus::ACTIVE
                                                     && $promotion->ends_at?->isFuture() === true,
            'promoted_until'  => $promotion !== null && $promotion->status               === PromotionStatus::ACTIVE && $promotion->ends_at?->isFuture() === true ? $promotion->ends_at->toIso8601String() : null,
            'payment_methods' => $offer->paymentMethods
                ->filter(fn (PaymentMethod $method): bool => filled($method->name))
                ->map(fn (PaymentMethod $method): array => $this->paymentMethod($method))
                ->values(),
            'updated_at' => $offer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{total_orders: int, completed_orders: int, completion_rate: float|int, avg_rating: ?float, positive_feedback: int}|null $stats
     * @return array<string, mixed>|null
     */
    private function advertiser(Offer $offer, ?array $stats): ?array
    {
        return $offer->user ? $this->person($offer->user, $stats) : null;
    }

    /**
     * @param  array{total_orders: int, completed_orders: int, completion_rate: float|int, avg_rating: ?float, positive_feedback: int}|null $stats
     * @return array<string, mixed>
     */
    private function person(User $user, ?array $stats): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'username'     => $user->username,
            'avatar_url'   => asset($user->avatar_alt),
            'kyc_verified' => $user->isKycVerified(),
            'online'       => $user->isOnline(),
            'stats'        => [
                'total_orders'        => (int) ($stats['total_orders'] ?? 0),
                'completed_orders'    => (int) ($stats['completed_orders'] ?? 0),
                'completion_rate'     => (float) ($stats['completion_rate'] ?? 0),
                'avg_rating'          => $stats['avg_rating'] ?? null,
                'positive_feedback'   => (int) ($stats['positive_feedback'] ?? 0),
                'avg_release_minutes' => $stats['avg_release_minutes'] ?? null,
            ],
        ];
    }

    /**
     * @return array{id: int, name: string, logo_url: ?string, country: ?string}
     */
    private function paymentMethod(PaymentMethod $method): array
    {
        return [
            'id'       => $method->id,
            'name'     => $method->name,
            'logo_url' => filled($method->logo) ? asset('storage/'.$method->logo) : null,
            'country'  => $method->country,
        ];
    }
}
