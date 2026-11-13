<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\Marketplace\OrderStatus;
use App\Enums\Marketplace\VendorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\VendorApplicationRequest;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Marketplace\MarketplaceOrderHandler;
use App\Services\Money\WalletPinGuard;
use App\Support\AmountEntry;
use App\Support\ApiMoney;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * The vendor marketplace from the app: the vendor directory, one vendor's pay
 * screen, paying them, applying to sell, and the customer's orders - what they
 * bought and, for a vendor, what they sold. Stripe Connect onboarding stays on
 * the website: it is a hosted flow at Stripe.
 *
 * Both follow the website's page. The directory is active vendors other than
 * the customer's own business, which is summarised once in meta instead,
 * because it is never payable to itself. The marketplace's own switch (the
 * admin's Marketplace Settings) answers 404 here as it does on the website,
 * on top of the catalog switch on the route.
 */
class MarketplaceController extends Controller
{
    private const SORTS = ['sales', 'newest', 'name'];

    public function vendors(Request $request): JsonResponse
    {
        $this->ensureMarketplaceEnabled();

        $validated = $request->validate([
            'sort'     => ['sometimes', Rule::in(self::SORTS)],
            'category' => ['sometimes', Rule::in(array_keys(Vendor::CATEGORIES))],
            'search'   => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $user     = $request->user();
        $sort     = $validated['sort']     ?? 'sales';
        $category = $validated['category'] ?? null;
        $search   = $validated['search']   ?? null;

        $page = $this->directory($user)
            ->with('user:id,first_name,last_name')
            ->withCount([
                'orders as completed_sales_count' => fn ($query) => $query->where('status', OrderStatus::RELEASED->value),
            ])
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when(filled($search), fn ($query) => $query->where('display_name', 'like', '%'.$search.'%'))
            ->when($sort === 'sales', fn ($query) => $query->orderByDesc('completed_sales_count'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('display_name'))
            ->when($sort === 'newest', fn ($query) => $query->latest('approved_at'))
            ->orderBy('id')
            ->paginate(12);

        $page->through(fn (Vendor $vendor): array => $this->vendor($vendor));

        $recent = MarketplaceOrder::query()
            ->with('vendor.user:id,first_name,last_name')
            ->where('buyer_id', $user->id)
            ->latest()
            ->limit(30)
            ->get()
            ->pluck('vendor')
            ->filter(fn (?Vendor $vendor): bool => $vendor !== null && $vendor->isActive() && $vendor->user_id !== $user->id)
            ->unique('id')
            ->take(6)
            ->map(fn (Vendor $vendor): array => $this->vendor($vendor, withSales: false))
            ->values();

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $settings = MarketplaceSetting::current();

        $payload['meta'] += [
            'total_vendors' => $this->directory($user)->count(),
            // The floor every order shares, so a directory row can say it.
            'min_order_amount' => (string) ($settings?->min_order_amount ?? 0),
            'currency'         => siteCurrency(),
            'categories'       => collect(Vendor::CATEGORIES)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => __($label)])->values(),
            'recent_vendors'   => $recent,
            'own_vendor'       => $this->ownVendor($user),
            'sort'             => $sort,
        ];

        return $response->setData($payload);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->ensureMarketplaceEnabled();

        $validated = $request->validate([
            'side' => ['sometimes', Rule::in(['purchases', 'sales'])],
        ]);

        $user   = $request->user();
        $side   = $validated['side'] ?? 'purchases';
        $vendor = $user->vendor;

        if ($side === 'sales') {
            // Not a vendor: nothing sold, and no other vendor's sales to show.
            $page = MarketplaceOrder::query()
                ->with(['buyer:id,first_name,last_name', 'currency:id,code'])
                ->where('vendor_id', $vendor?->id ?? 0)
                ->latest()
                ->latest('id')
                ->paginate(10);

            $page->through(fn (MarketplaceOrder $order): array => $this->order($order) + [
                'buyer_name' => $order->buyer?->name,
                'vendor_net' => ApiMoney::amount((string) $order->vendor_net, $order->currency?->code),
            ]);
        } else {
            $page = MarketplaceOrder::query()
                ->with(['vendor:id,display_name', 'currency:id,code'])
                ->where('buyer_id', $user->id)
                ->latest()
                ->latest('id')
                ->paginate(10);

            $page->through(fn (MarketplaceOrder $order): array => $this->order($order) + [
                'vendor' => $order->vendor ? ['id' => $order->vendor->id, 'display_name' => $order->vendor->display_name] : null,
            ]);
        }

        $response = ApiResponse::paginated($page);
        $payload  = $response->getData(true);

        $payload['meta'] += [
            'side'       => $side,
            'own_vendor' => $this->ownVendor($user),
        ];

        return $response->setData($payload);
    }

    /**
     * One vendor and the terms an order with them is placed on - the
     * website's pay screen. Only an active vendor has one.
     */
    public function showVendor(Request $request, string $vendor): JsonResponse
    {
        $this->ensureMarketplaceEnabled();

        $vendor = Vendor::query()
            ->with('user:id,first_name,last_name')
            ->withCount([
                'orders as completed_sales_count' => fn ($query) => $query->where('status', OrderStatus::RELEASED->value),
            ])
            ->where('status', VendorStatus::ACTIVE)
            ->findOrFail($vendor);

        $settings = MarketplaceSetting::current();

        return ApiResponse::ok($this->vendor($vendor) + [
            'is_own' => (int) $vendor->user_id === (int) $request->user()->id,
            'terms'  => [
                'commission_percent' => (string) ($vendor->commission_pct_override ?? $settings?->commission_pct ?? 0),
                'commission_fixed'   => (string) ($settings?->commission_fixed ?? 0),
                'min_order_amount'   => (string) ($settings?->min_order_amount ?? 0),
                'max_order_amount'   => $settings?->max_order_amount !== null ? (string) $settings->max_order_amount : null,
            ],
        ]);
    }

    /**
     * Pay a vendor from one of the customer's wallets.
     *
     * The split between the vendor and the platform is the server's:
     * MarketplaceOrderHandler takes the gross amount and works out the
     * commission itself. A gateway split (Stripe Connect) is paid on the
     * provider's hosted page, so the order comes back pending with the URL to
     * finish on; its webhook releases it. The wallet PIN is asked for when
     * one is set - the website does not ask.
     */
    public function pay(Request $request, string $vendor, MarketplaceOrderHandler $orders, WalletPinGuard $pins): JsonResponse
    {
        $this->ensureMarketplaceEnabled();

        $uuid   = $request->input('wallet_id');
        $wallet = is_string($uuid)
            ? $request->user()->wallets()->with('currency')->where('uuid', $uuid)->first()
            : null;

        $validated = $request->validate([
            'wallet_id'  => ['required', 'string'],
            'amount'     => AmountEntry::rules($wallet?->currency?->code ?? siteCurrency()),
            'remarks'    => ['nullable', 'string', 'max:255'],
            'wallet_pin' => ['nullable', 'string'],
        ]);

        if ($wallet === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $vendor = Vendor::query()->where('status', VendorStatus::ACTIVE)->findOrFail($vendor);

        $pins->assert($request->user(), $validated['wallet_pin'] ?? null);

        $result = $orders->checkout(
            buyer: $request->user(),
            vendor: $vendor,
            gross: (float) $validated['amount'],
            currency: $wallet->currency,
            remarks: $validated['remarks'] ?? null,
        );

        $order = $result->order->load(['vendor:id,display_name', 'currency:id,code']);

        return ApiResponse::created($this->order($order) + [
            'vendor'       => ['id' => $vendor->id, 'display_name' => $vendor->display_name],
            'redirect_url' => $result->redirectUrl,
        ]);
    }

    /**
     * Apply to sell on the marketplace. An administrator reviews the
     * application; one vendor profile per account.
     */
    public function apply(VendorApplicationRequest $request): JsonResponse
    {
        $this->ensureMarketplaceEnabled();

        $user = $request->user();

        if (Vendor::query()->where('user_id', $user->id)->exists()) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('You already have a vendor profile.'));
        }

        $validated = $request->validated();

        $vendor = Vendor::query()->create([
            'user_id'      => $user->id,
            'display_name' => $validated['display_name'],
            'about'        => $validated['about']    ?? null,
            'location'     => $validated['location'] ?? null,
            'category'     => $validated['category'],
            'status'       => VendorStatus::PENDING,
        ]);

        return ApiResponse::created($this->ownVendor($user->refresh()) ?? ['id' => $vendor->id]);
    }

    /**
     * Active vendors the customer can pay: everyone's but their own.
     *
     * @return Builder<Vendor>
     */
    private function directory(User $user): Builder
    {
        return Vendor::query()
            ->where('status', VendorStatus::ACTIVE)
            ->where('user_id', '!=', $user->id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ownVendor(User $user): ?array
    {
        $vendor = $user->vendor;

        if (! $vendor) {
            return null;
        }

        return [
            'id'           => $vendor->id,
            'display_name' => $vendor->display_name,
            'status'       => $vendor->status->value,
            'category'     => $vendor->category,
            'location'     => $vendor->location,
            'sales'        => MarketplaceOrder::query()
                ->where('vendor_id', $vendor->id)
                ->where('status', OrderStatus::RELEASED->value)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vendor(Vendor $vendor, bool $withSales = true): array
    {
        $card = [
            'id'             => $vendor->id,
            'display_name'   => $vendor->display_name,
            'initials'       => $vendor->initials(),
            'about'          => $vendor->about,
            'category'       => $vendor->category,
            'category_label' => $vendor->categoryLabel(),
            'location'       => $vendor->location,
            'owner_name'     => $vendor->user?->name,
            'since'          => ($vendor->approved_at ?? $vendor->created_at)?->toIso8601String(),
            // The website's pay page for this vendor, for the share button.
            'share_url' => Route::has('user.marketplace.pay') ? route('user.marketplace.pay', ['vendor' => $vendor->id]) : null,
        ];

        if ($withSales) {
            $card['completed_sales'] = (int) $vendor->completed_sales_count;
        }

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function order(MarketplaceOrder $order): array
    {
        $code = $order->currency?->code;

        return [
            'id'          => $order->id,
            'reference'   => $order->trx_reference,
            'amount'      => ApiMoney::amount((string) $order->gross_amount, $code),
            'currency'    => $code,
            'status'      => $order->status->value,
            'remarks'     => $order->remarks,
            'created_at'  => $order->created_at?->toIso8601String(),
            'released_at' => $order->released_at?->toIso8601String(),
            'refunded_at' => $order->refunded_at?->toIso8601String(),
        ];
    }

    private function ensureMarketplaceEnabled(): void
    {
        abort_unless((bool) (MarketplaceSetting::current()?->enabled), 404);
    }
}
