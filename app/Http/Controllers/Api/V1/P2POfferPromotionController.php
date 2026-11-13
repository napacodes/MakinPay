<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Models\P2P\Offer;
use App\Models\P2P\PromotionPackage;
use App\Models\Wallet;
use App\Services\Money\WalletPinGuard;
use App\Services\P2P\P2POfferMarketplace;
use App\Services\P2P\P2POfferPromotionService;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Promoting one of the customer's P2P trade ads, from the app: the packages
 * on offer for it, a quote in the paying wallet's currency, and the purchase.
 *
 * All of it goes through P2POfferPromotionService, the website's own - the
 * purchase locks the ad's promotion row, debits the wallet and extends or
 * opens the promotion in one transaction. Only the advertiser's own ad, and
 * only one that can still be traded. The purchase carries the money limit, a
 * required replay key, and the wallet PIN when one is set.
 */
class P2POfferPromotionController extends Controller
{
    public function __construct(
        private readonly P2POfferPromotionService $promotions,
        private readonly P2POfferMarketplace $marketplace,
    ) {}

    public function show(Request $request, string $offer): JsonResponse
    {
        $offer = $this->ownOperationalOffer($request, $offer);

        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        $offer->load('promotion');

        return ApiResponse::ok([
            'packages'  => $this->packagesFor($offer)->get()->map(fn (PromotionPackage $package): array => $this->package($package))->values(),
            'promotion' => $offer->promotion ? [
                'status'     => $offer->promotion->status?->value,
                'starts_at'  => $offer->promotion->starts_at?->toIso8601String(),
                'ends_at'    => $offer->promotion->ends_at?->toIso8601String(),
                'package_id' => $offer->promotion->package_id,
            ] : null,
        ]);
    }

    public function quote(Request $request, string $offer): JsonResponse
    {
        $offer = $this->ownOperationalOffer($request, $offer);

        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        [$package, $wallet] = $this->resolve($request, $offer);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        return ApiResponse::ok($this->quoteBody($this->promotions->quote($package, $wallet)));
    }

    public function purchase(Request $request, string $offer, WalletPinGuard $pins): JsonResponse
    {
        $offer = $this->ownOperationalOffer($request, $offer);

        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        [$package, $wallet] = $this->resolve($request, $offer, ['wallet_pin' => ['nullable', 'string']]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $pins->assert($request->user(), $request->input('wallet_pin'));

        $promotion = $this->promotions->purchase($offer, $package, $wallet, (int) $request->user()->id);

        return ApiResponse::created([
            'status'        => $promotion->status?->value,
            'starts_at'     => $promotion->starts_at?->toIso8601String(),
            'ends_at'       => $promotion->ends_at?->toIso8601String(),
            'paid_amount'   => ApiMoney::amount((string) $promotion->paid_amount, $promotion->paid_currency),
            'paid_currency' => $promotion->paid_currency,
            'reference'     => $promotion->trx_id,
        ]);
    }

    /**
     * The customer's own ad, and one whose currencies are still switched on.
     */
    private function ownOperationalOffer(Request $request, string $offer): Offer|JsonResponse
    {
        $offer = Offer::query()->where('user_id', $request->user()->id)->findOrFail($offer);

        if (! $this->marketplace->isOperational($offer)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('This trade ad cannot be promoted because one of its currencies is unavailable.'));
        }

        return $offer;
    }

    /**
     * The package - one on offer for this ad's side - and the paying wallet,
     * one of the customer's own active ones, by uuid.
     *
     * @param  array<string, array<int, string>>      $extra
     * @return array{0: PromotionPackage, 1: ?Wallet}
     */
    private function resolve(Request $request, Offer $offer, array $extra = []): array
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer'],
            'wallet_id'  => ['required', 'string'],
        ] + $extra);

        $package = $this->packagesFor($offer)->findOrFail($validated['package_id']);

        $wallet = $request->user()->wallets()
            ->with('currency')
            ->where('uuid', $validated['wallet_id'])
            ->where('status', true)
            ->first();

        return [$package, $wallet];
    }

    /**
     * Active, public packages that apply to this ad's side - the website's
     * promote screen's own filter.
     *
     * @return Builder<PromotionPackage>
     */
    private function packagesFor(Offer $offer): Builder
    {
        $side = strtoupper((string) ($offer->side?->value ?? ''));

        return PromotionPackage::query()
            ->where('status', true)
            ->when(Schema::hasColumn('p2p_promotion_packages', 'visibility'), fn (Builder $query) => $query->where('visibility', 'PUBLIC'))
            ->when($side !== '' && Schema::hasColumn('p2p_promotion_packages', 'applies_to'), fn (Builder $query) => $query->whereIn('applies_to', ['BOTH', $side]))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function package(PromotionPackage $package): array
    {
        $currency = (string) ($package->base_currency ?: siteCurrency());

        return [
            'id'               => $package->id,
            'name'             => $package->name,
            'duration_minutes' => (int) $package->duration_minutes,
            'price'            => ApiMoney::amount((string) $package->effectiveBasePrice(), $currency),
            'currency'         => $currency,
            'billing_type'     => $package->billing_type,
            'features'         => $package->features,
            'accent_color'     => $package->accent_color,
            'applies_to'       => $package->applies_to,
        ];
    }

    /**
     * @param  array<string, float|string> $quote The promotion service's quote: base and paid currency, price, amount and rate.
     * @return array<string, mixed>
     */
    private function quoteBody(array $quote): array
    {
        return [
            'base_currency' => $quote['base_currency'],
            'base_price'    => ApiMoney::amount((string) $quote['base_price'], $quote['base_currency']),
            'paid_currency' => $quote['paid_currency'],
            'paid_amount'   => ApiMoney::amount((string) $quote['paid_amount'], $quote['paid_currency']),
            'exchange_rate' => (string) $quote['exchange_rate'],
        ];
    }
}
