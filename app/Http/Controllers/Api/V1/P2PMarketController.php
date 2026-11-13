<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\P2P\OrderSide;
use App\Http\Controllers\Controller;
use App\Models\P2P\MarketAlert;
use App\Models\P2P\PaymentMethod;
use App\Services\CurrencyService;
use App\Services\P2P\P2PMarketPrice;
use App\Services\P2P\P2POfferMarketplace;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use App\Support\DecimalMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The market's reference line and the searches a customer saved on it.
 */
class P2PMarketController extends Controller
{
    public function __construct(private readonly P2POfferMarketplace $marketplace, private readonly CurrencyService $currencies, private readonly P2PMarketPrice $prices) {}

    /**
     * Where the pair trades: the site's own cross rate as the mid-price, and
     * the best ad on the side the customer is looking at, with how far it
     * sits from the mid.
     */
    public function price(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset' => ['required', 'string', 'max:20'],
            'fiat'  => ['required', 'string', 'max:20'],
            'side'  => ['sometimes', Rule::enum(OrderSide::class)],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        $asset = strtoupper($validated['asset']);
        $fiat  = strtoupper($validated['fiat']);
        $side  = $this->marketplace->effectiveSide($validated['side'] ?? null);
        $mid   = $this->currencies->crossRate($asset, $fiat);

        $page      = $this->marketplace->search($request->user(), ['side' => $side->value, 'currency' => $asset, 'fiat' => $fiat, 'sort' => 'price'], P2PMarketPrice::LADDER_SIZE);
        $best      = $page->getCollection()->first();
        $ladder    = $page->getCollection()->map(fn ($offer): string => ApiMoney::amount((string) $offer->price, $fiat))->values()->all();
        $mine      = isset($validated['price']) && $validated['price'] !== null ? (string) $validated['price'] : null;
        $bestPrice = $best === null ? null : (string) $best->price;
        $pct       = null;
        if ($bestPrice !== null && $mid !== null && DecimalMoney::compare($mid, '0') > 0) {
            $pct = DecimalMoney::round(DecimalMoney::multiply(DecimalMoney::divide(DecimalMoney::subtract($bestPrice, $mid, DecimalMoney::CALCULATION_SCALE), $mid), '100'), 1);
        }

        return ApiResponse::ok([
            'asset'    => $asset,
            'fiat'     => $fiat,
            'side'     => $side->value,
            'mid'      => $mid       === null ? null : ApiMoney::amount($mid, $fiat),
            'best'     => $bestPrice === null ? null : ApiMoney::amount($bestPrice, $fiat),
            'best_pct' => $pct,
            'offers'   => $page->total(),
            'ladder'   => $ladder,
            'rank'     => $mine === null ? null : $this->prices->rank($mine, $ladder, $side),
            'as_of'    => now()->toIso8601String(),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        return ApiResponse::ok([
            'alerts' => $request->user()->hasMany(MarketAlert::class)->whereNull('notified_at')->latest('id')->get()->map(fn (MarketAlert $a): array => $this->alert($a))->values(),
        ]);
    }

    public function storeAlert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'side'              => ['required', Rule::enum(OrderSide::class)],
            'asset'             => ['required', 'string', 'max:20'],
            'fiat'              => ['required', 'string', 'max:20'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists(PaymentMethod::class, 'id')],
            'amount'            => ['nullable', 'numeric', 'min:0'],
        ]);
        $user  = $request->user();
        $keys  = ['user_id' => $user->id, 'side' => $validated['side'], 'asset' => strtoupper($validated['asset']), 'fiat' => strtoupper($validated['fiat']), 'payment_method_id' => $validated['payment_method_id'] ?? null];
        $alert = MarketAlert::query()->where($keys)->whereNull('notified_at')->first()
            ?? MarketAlert::query()->create($keys + ['amount' => $validated['amount'] ?? null]);

        return ApiResponse::created(['alert' => $this->alert($alert)]);
    }

    public function destroyAlert(Request $request, string $alert): JsonResponse
    {
        MarketAlert::query()->where('user_id', $request->user()->id)->whereKey($alert)->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(MarketAlert $alert): array
    {
        return [
            'id'                => $alert->id,
            'side'              => $alert->side,
            'asset'             => $alert->asset,
            'fiat'              => $alert->fiat,
            'payment_method_id' => $alert->payment_method_id,
            'amount'            => $alert->amount === null ? null : ApiMoney::amount((string) $alert->amount, $alert->asset),
            'created_at'        => $alert->created_at?->toIso8601String(),
        ];
    }
}
