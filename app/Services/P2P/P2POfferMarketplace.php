<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Enums\KycStatus;
use App\Enums\P2P\OfferStatus;
use App\Enums\P2P\OrderSide;
use App\Enums\P2P\OrderStatus;
use App\Models\P2P\Block;
use App\Models\P2P\Offer;
use App\Models\P2P\Order as P2POrder;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\SubscriptionFeatureService;
use App\Support\DecimalMoney;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The P2P marketplace feed: which trade ads a customer is shown, in what
 * order, and the trader statistics and price signals printed beside them.
 *
 * The website's marketplace and the app both read it, so a rule about who is
 * shown to whom - never your own ads, never an ad on a switched-off wallet or
 * currency, promoted ads first - is written once.
 */
class P2POfferMarketplace
{
    public function __construct(private readonly SubscriptionFeatureService $subscriptionFeatures, private readonly CurrencyService $currencies) {}

    /**
     * @param array{side?: ?string, currency?: ?string, fiat?: ?string, payment_method_id?: ?int, country?: ?string, amount_min?: mixed, amount_max?: mixed, sort?: ?string} $filters
     */
    public function search(User $viewer, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Offer::query()
            ->leftJoin('p2p_offer_promotions as promo', 'promo.offer_id', '=', 'p2p_offers.id')
            ->select('p2p_offers.*')
            ->with(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'user.kycSubmission', 'promotion.package'])
            ->withCount([
                'orders as active_orders_count' => function ($q) {
                    $q->whereIn('status', [
                        OrderStatus::PENDING->value,
                        OrderStatus::PAID->value,
                        OrderStatus::DISPUTED->value,
                    ]);
                },
            ])
            ->where('p2p_offers.status', OfferStatus::ACTIVE)
            ->whereHas('wallet', fn ($walletQuery) => $walletQuery->active())
            ->whereHas('quoteCurrency', fn ($currencyQuery) => $currencyQuery->active())
            ->orderByRaw(
                'CASE WHEN promo.status = ? AND promo.ends_at IS NOT NULL AND promo.ends_at > ? THEN 0 ELSE 1 END',
                ['ACTIVE', now()]
            );

        $effectiveSide = $this->effectiveSide($filters['side'] ?? null);
        $query->where('p2p_offers.side', $effectiveSide);

        // The marketplace is a counterparty feed: every row must be tradeable.
        // A user cannot trade against their own ad, so exclude their listings
        // here — they are managed on the "My Trade Ads" screen instead.
        $query->where('p2p_offers.user_id', '!=', (int) $viewer->id);

        // Nor an ad from anyone the viewer has blocked.
        $query->whereNotIn('p2p_offers.user_id', Block::blockedBy((int) $viewer->id));

        if ($currency = (string) ($filters['currency'] ?? '')) {
            $query->whereHas('wallet.currency', function ($q) use ($currency) {
                $q->where('code', $currency);
            });
        }
        if ($fiat = (string) ($filters['fiat'] ?? '')) {
            $query->whereHas('quoteCurrency', function ($q) use ($fiat) {
                $q->where('code', $fiat);
            });
        }
        if ($pmId = (int) ($filters['payment_method_id'] ?? 0)) {
            $query->whereHas('paymentMethods', function ($q) use ($pmId) {
                $q->where('p2p_payment_methods.id', $pmId);
            });
        }

        if ($country = (string) ($filters['country'] ?? '')) {
            $query->whereHas('paymentMethods', function ($q) use ($country) {
                $q->where('country', $country);
            });
        }

        $amountMinInput = $filters['amount_min'] ?? null;
        $amountMaxInput = $filters['amount_max'] ?? null;

        $amountMin = is_numeric($amountMinInput) ? max(0, (float) $amountMinInput) : null;
        $amountMax = is_numeric($amountMaxInput) ? max(0, (float) $amountMaxInput) : null;

        if ($amountMin !== null && $amountMax !== null && $amountMin > $amountMax) {
            [$amountMin, $amountMax] = [$amountMax, $amountMin];
        }

        if ($amountMin !== null) {
            $query->where(function ($q) use ($amountMin) {
                $q->whereNull('max_amount')
                    ->orWhere('max_amount', '>=', $amountMin);
            });
        }

        if ($amountMax !== null) {
            $query->where('min_amount', '<=', $amountMax);
        }

        // One figure the customer wants to trade: only ads whose limits take it.
        if (is_numeric($filters['amount'] ?? null) && (float) $filters['amount'] > 0) {
            $amount = (float) $filters['amount'];
            $query->where('min_amount', '<=', $amount)
                ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount));
        }
        if (filter_var($filters['verified'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereHas('user.kycSubmission', fn ($q) => $q->where('status', KycStatus::APPROVED));
        }
        if (filter_var($filters['online'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereHas('user', fn ($q) => $q->where('last_seen_at', '>=', now()->subMinutes(User::ONLINE_WITHIN_MINUTES)));
        }

        $sort = (string) ($filters['sort'] ?? '');
        if (in_array($sort, ['completion', 'trades'], true)) {
            $makerStats = P2POrder::query()
                ->selectRaw('maker_id, COUNT(*) as maker_total_orders, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as maker_completed_orders', [OrderStatus::COMPLETED->value])
                ->groupBy('maker_id');

            $query->leftJoinSub($makerStats, 'maker_stats', 'maker_stats.maker_id', '=', 'p2p_offers.user_id');

            if ($sort === 'trades') {
                $query->orderByRaw('COALESCE(maker_stats.maker_total_orders, 0) DESC');
            } else {
                $query->orderByRaw('CASE WHEN COALESCE(maker_stats.maker_total_orders, 0) = 0 THEN 0 ELSE maker_stats.maker_completed_orders * 1.0 / maker_stats.maker_total_orders END DESC');
            }
        } elseif ($sort === 'release') {
            $query->leftJoinSub($this->releaseStats(), 'release_stats', 'release_stats.seller_id', '=', 'p2p_offers.user_id')
                ->orderByRaw('CASE WHEN release_stats.avg_release_seconds IS NULL THEN 1 ELSE 0 END, release_stats.avg_release_seconds ASC');
        } elseif ($sort === 'price_desc') {
            $query->orderBy('p2p_offers.price', 'desc');
        } elseif ($sort === 'price_asc') {
            $query->orderBy('p2p_offers.price', 'asc');
        } elseif ($effectiveSide === OrderSide::BUY) {
            $query->orderBy('p2p_offers.price', 'desc');
        } else {
            $query->orderBy('p2p_offers.price', 'asc');
        }

        return $query->paginate($perPage);
    }

    /**
     * The side a marketplace request lists: the one asked for, or sell ads.
     */
    public function effectiveSide(?string $side): OrderSide
    {
        return OrderSide::tryFrom((string) $side) ?? OrderSide::SELL;
    }

    /**
     * Each advertiser's record as a maker: orders, completion, ratings.
     *
     * @param  Collection<int, int|string>                                                                                                         $userIds
     * @return array<int, array{total_orders: int, completed_orders: int, completion_rate: float|int, avg_rating: ?float, positive_feedback: int}>
     */
    public function traderStats(Collection $userIds): array
    {
        $userIds = $userIds->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        $orderAgg = P2POrder::query()
            ->select('maker_id')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_orders', [OrderStatus::COMPLETED->value])
            ->whereIn('maker_id', $userIds)
            ->groupBy('maker_id')
            ->get()
            ->keyBy('maker_id');

        $feedbackAgg = DB::table('p2p_offer_feedback as f')
            ->join('p2p_offers as o', 'o.id', '=', 'f.offer_id')
            ->whereIn('o.user_id', $userIds)
            ->select('o.user_id')
            ->selectRaw('AVG(f.rating) as avg_rating')
            ->selectRaw('SUM(CASE WHEN f.rating >= 4 THEN 1 ELSE 0 END) as positive_feedback')
            ->groupBy('o.user_id')
            ->get()
            ->keyBy('user_id');

        $releaseAgg = $this->releaseStats()->havingRaw('seller_id IN ('.implode(',', array_fill(0, count($userIds), '?')).')', $userIds->map(fn ($v) => (int) $v)->all())->get()->keyBy('seller_id');

        $stats = [];

        foreach ($userIds as $id) {
            $totalOrders      = (int) ($orderAgg[$id]->total_orders ?? 0);
            $completedOrders  = (int) ($orderAgg[$id]->completed_orders ?? 0);
            $completionRate   = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $avgRating        = isset($feedbackAgg[$id]) ? (float) $feedbackAgg[$id]->avg_rating : null;
            $positiveFeedback = (int) ($feedbackAgg[$id]->positive_feedback ?? 0);

            $stats[(int) $id] = [
                'total_orders'      => $totalOrders,
                'completed_orders'  => $completedOrders,
                'completion_rate'   => $completionRate,
                'avg_rating'        => $avgRating,
                'positive_feedback' => $positiveFeedback,
                // How long, on average, this trader takes to release after the
                // buyer marks paid - as the seller of a SELL ad, or the taker
                // paying out on a BUY ad. Null until they have released once.
                'avg_release_minutes' => isset($releaseAgg[$id]) && $releaseAgg[$id]->avg_release_seconds !== null ? (int) ceil(round((float) $releaseAgg[$id]->avg_release_seconds) / 60) : null,
            ];
        }

        return $stats;
    }

    /**
     * Seconds from marked-paid to released, averaged per seller over their
     * completed trades.
     */
    /**
     * Seconds from one timestamp column to another, in the dialect of the
     * connection the tests and the site actually run on.
     */
    private static function secondsBetween(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => '(julianday('.$to.') - julianday('.$from.')) * 86400',
            'pgsql'  => 'EXTRACT(EPOCH FROM ('.$to.' - '.$from.'))',
            default  => 'TIMESTAMPDIFF(SECOND, '.$from.', '.$to.')',
        };
    }

    private function releaseStats(): Builder
    {
        return DB::table('p2p_orders as o')
            ->join('p2p_offers as f', 'f.id', '=', 'o.offer_id')
            ->selectRaw("CASE WHEN f.side = 'SELL' THEN o.maker_id ELSE o.taker_id END as seller_id")
            ->selectRaw('AVG('.self::secondsBetween('o.paid_at', 'o.completed_at').') as avg_release_seconds')
            ->where('o.status', OrderStatus::COMPLETED->value)
            ->whereNotNull('o.paid_at')
            ->whereNotNull('o.completed_at')
            ->groupByRaw("CASE WHEN f.side = 'SELL' THEN o.maker_id ELSE o.taker_id END");
    }

    /**
     * Build a per-offer price-competitiveness signal comparing each offer's
     * price to the live market average for the same asset + fiat on the same
     * side. This is a real, computed indicator — not a fabricated trend: a
     * positive advantage means the price is better for the viewer than the
     * current market average (cheaper when buying, higher when selling).
     *
     * @param  Collection<int, Offer>                                            $offers
     * @return array<int, array{pct: float, direction: string, favorable: bool}>
     */
    public function priceTrends(Collection $offers, OrderSide $side): array
    {
        if ($offers->isEmpty()) {
            return [];
        }

        $benchmarks = Offer::query()
            ->join('wallets', 'wallets.id', '=', 'p2p_offers.wallet_id')
            ->where('p2p_offers.status', OfferStatus::ACTIVE)
            ->where('p2p_offers.side', $side)
            ->groupBy('wallets.currency_id', 'p2p_offers.quote_currency_id')
            ->selectRaw('wallets.currency_id as base_currency_id, p2p_offers.quote_currency_id as quote_currency_id, AVG(p2p_offers.price) as avg_price, COUNT(*) as offers_count')
            ->get()
            ->keyBy(fn ($row) => $row->base_currency_id.':'.$row->quote_currency_id);

        $viewerBuys = $side === OrderSide::SELL;
        $trends     = [];

        foreach ($offers as $offer) {
            $key       = ($offer->wallet?->currency_id).':'.$offer->quote_currency_id;
            $benchmark = $benchmarks[$key] ?? null;

            if ($benchmark === null || (int) $benchmark->offers_count < 2) {
                continue;
            }

            $average = (float) $benchmark->avg_price;
            $price   = (float) $offer->price;

            if ($average <= 0) {
                continue;
            }

            $advantage = $viewerBuys
                ? (($average - $price) / $average) * 100
                : (($price - $average) / $average) * 100;

            $trends[(int) $offer->id] = [
                'pct'       => round(abs($advantage), 2),
                'direction' => $advantage > 0.05 ? 'up' : ($advantage < -0.05 ? 'down' : 'flat'),
                'favorable' => $advantage > 0.05,
            ];
        }

        return $trends;
    }

    /**
     * Asset codes with at least one tradeable ad, for the marketplace filter.
     *
     * @return Collection<int, string>
     */
    public function assetOptions(): Collection
    {
        return Offer::query()
            ->where('p2p_offers.status', OfferStatus::ACTIVE)
            ->join('wallets', 'wallets.id', '=', 'p2p_offers.wallet_id')
            ->join('currencies', 'currencies.id', '=', 'wallets.currency_id')
            ->where('wallets.status', true)
            ->where('currencies.status', true)
            ->select('currencies.code')
            ->distinct()
            ->orderBy('currencies.code')
            ->pluck('currencies.code')
            ->filter()
            ->values();
    }

    /**
     * Fiat codes with at least one tradeable ad, for the marketplace filter.
     *
     * @return Collection<int, string>
     */
    public function fiatOptions(): Collection
    {
        return Offer::query()
            ->where('p2p_offers.status', OfferStatus::ACTIVE)
            ->join('wallets', 'wallets.id', '=', 'p2p_offers.wallet_id')
            ->join('currencies', 'currencies.id', '=', 'wallets.currency_id')
            ->join('currencies as quote_currencies', 'quote_currencies.id', '=', 'p2p_offers.quote_currency_id')
            ->where('wallets.status', true)
            ->where('currencies.status', true)
            ->where('quote_currencies.status', true)
            ->select('quote_currencies.code')
            ->distinct()
            ->orderBy('quote_currencies.code')
            ->pluck('quote_currencies.code')
            ->filter()
            ->values();
    }

    /**
     * What the customer's subscription plan lets them do on the marketplace.
     *
     * @return array{can_trade: bool, can_create_ad: bool, trade_limit: ?int, ad_limit: ?int}
     */
    public function subscriptionGates(User $user): array
    {
        $canTrade   = $this->subscriptionFeatures->canUse($user, 'p2p_trading');
        $tradeLimit = $this->subscriptionFeatures->getLimit($user, 'p2p_trade_limit');
        $adLimit    = $this->subscriptionFeatures->getLimit($user, 'p2p_ad_limit');

        return [
            'can_trade'     => $canTrade && ($tradeLimit === null || $tradeLimit > 0),
            'can_create_ad' => $canTrade && ($adLimit === null || $adLimit > 0),
            'trade_limit'   => $tradeLimit,
            'ad_limit'      => $adLimit,
            // Why a customer who cannot trade cannot: the plan, their identity, or both.
            'needs_plan' => ! $canTrade,
            'needs_kyc'  => ! $user->isKycVerified(),
        ];
    }

    /**
     * The customer's own trade ads, with their order counts.
     */
    public function ownOffers(int $userId, string $search, string $status, string $sort, int $perPage = 15): LengthAwarePaginator
    {
        $offersQuery = Offer::query()
            ->with(['wallet.currency', 'quoteCurrency', 'paymentMethods', 'promotion.package'])
            ->withCount([
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
            ])
            ->where('user_id', $userId)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('id', (int) $search)
                        ->orWhere('terms', 'like', '%'.$search.'%')
                        ->orWhereHas('wallet.currency', function ($walletQuery) use ($search) {
                            $walletQuery->where('code', 'like', '%'.$search.'%')
                                ->orWhere('name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('paymentMethods', function ($paymentMethodQuery) use ($search) {
                            $paymentMethodQuery->where('name', 'like', '%'.$search.'%');
                        });
                });
            });

        if ($status !== '') {
            if ($status === 'PROMOTED') {
                $offersQuery->whereHas('promotion', function ($query) {
                    $query->where('status', 'ACTIVE')
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '>', now());
                });
            } elseif (in_array($status, array_map(fn ($case) => $case->value, OfferStatus::cases()), true)) {
                $offersQuery->where('status', $status);
            }
        }

        $offersQuery->orderByRaw(
            'CASE p2p_offers.status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
            [OfferStatus::ACTIVE->value, OfferStatus::PAUSED->value]
        );

        match ($sort) {
            'oldest'      => $offersQuery->oldest('updated_at'),
            'price_low'   => $offersQuery->orderBy('price')->latest('updated_at'),
            'price_high'  => $offersQuery->orderByDesc('price')->latest('updated_at'),
            'most_orders' => $offersQuery->orderByDesc('total_orders_count')->latest('updated_at'),
            default       => $offersQuery->latest('updated_at'),
        };

        return $offersQuery->paginate($perPage);
    }

    /**
     * The headline numbers over the customer's own trade ads and the orders
     * placed against them.
     *
     * @return array{total_offers: int, active_offers: int, paused_offers: int, promoted_offers: int, open_orders: int, completed_orders: int, disputed_orders: int}
     */
    /**
     * The advertiser's last thirty days against the thirty before: volume in
     * the site currency, trades completed, how many trades ended well, and
     * how fast they release. Volume converts each trade's fiat at today's
     * rate - a figure to read, not to settle.
     *
     * @return array{currency: string, volume: string, previous_volume: string, volume_change_pct: ?string, trades: int, completion_rate: float, avg_release_minutes: ?int}
     */
    public function ownerStats(int $userId): array
    {
        $currency = (string) siteCurrency();
        $since    = now()->subDays(30);
        $before   = now()->subDays(60);

        $window = fn ($from, $to) => P2POrder::query()
            ->with('offer.quoteCurrency')
            ->where(fn ($q) => $q->where('maker_id', $userId)->orWhere('taker_id', $userId))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->get();

        $recent   = $window($since, now()->addSecond());
        $previous = $window($before, $since);

        $volume = function ($orders) use ($currency): string {
            $sum = '0';
            foreach ($orders as $order) {
                if ($order->status !== OrderStatus::COMPLETED) {
                    continue;
                }
                $fiat = $order->offer?->quoteCurrency?->code;
                $rate = $fiat === null ? null : ($fiat === $currency ? '1' : $this->currencies->crossRate($fiat, $currency));
                if ($rate === null) {
                    continue;
                }
                $sum = DecimalMoney::add($sum, DecimalMoney::multiply((string) $order->total, $rate));
            }

            return DecimalMoney::round($sum, 2);
        };

        $recentVolume   = $volume($recent);
        $previousVolume = $volume($previous);
        $change         = DecimalMoney::compare($previousVolume, '0') > 0
            ? DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide(DecimalMoney::subtract($recentVolume, $previousVolume, DecimalMoney::CALCULATION_SCALE), $previousVolume), '100'), 1)
            : null;

        $settled   = $recent->filter(fn ($o) => in_array($o->status, [OrderStatus::COMPLETED, OrderStatus::CANCELLED, OrderStatus::EXPIRED], true));
        $completed = $recent->where('status', OrderStatus::COMPLETED)->count();

        return [
            'currency'            => $currency,
            'volume'              => $recentVolume,
            'previous_volume'     => $previousVolume,
            'volume_change_pct'   => $change,
            'trades'              => $completed,
            'completion_rate'     => $settled->count() > 0 ? round($completed / $settled->count() * 100, 1) : 0.0,
            'avg_release_minutes' => $this->traderStats(collect([$userId]))[$userId]['avg_release_minutes'] ?? null,
        ];
    }

    public function ownerSummary(int $userId): array
    {
        $offerStatusCounts = Offer::query()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->where('user_id', $userId)
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [(string) $item->getRawOriginal('status') => $item];
            });

        $orderStatusCounts = P2POrder::query()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->where('maker_id', $userId)
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [(string) $item->getRawOriginal('status') => $item];
            });

        return [
            'total_offers'    => (int) $offerStatusCounts->sum('aggregate'),
            'active_offers'   => (int) ($offerStatusCounts[OfferStatus::ACTIVE->value]->aggregate ?? 0),
            'paused_offers'   => (int) ($offerStatusCounts[OfferStatus::PAUSED->value]->aggregate ?? 0),
            'promoted_offers' => Offer::query()
                ->where('user_id', $userId)
                ->whereHas('promotion', function ($q) {
                    $q->where('status', 'ACTIVE')
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '>', now());
                })
                ->count(),
            'open_orders' => (int) (($orderStatusCounts[OrderStatus::PENDING->value]->aggregate ?? 0)
                + ($orderStatusCounts[OrderStatus::PAID->value]->aggregate ?? 0)
                + ($orderStatusCounts[OrderStatus::DISPUTED->value]->aggregate ?? 0)),
            'completed_orders' => (int) ($orderStatusCounts[OrderStatus::COMPLETED->value]->aggregate ?? 0),
            'disputed_orders'  => (int) ($orderStatusCounts[OrderStatus::DISPUTED->value]->aggregate ?? 0),
        ];
    }

    /**
     * Whether an offer can be traded: its wallet, asset and fiat all switched on.
     */
    public function isOperational(Offer $offer): bool
    {
        $offer->loadMissing(['wallet.currency', 'quoteCurrency']);

        return (bool) $offer->wallet?->status
            && (bool) $offer->wallet?->currency?->status
            && (bool) $offer->quoteCurrency?->status;
    }
}
