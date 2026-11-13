<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend\Marketplace;

use App\Enums\Marketplace\OrderStatus;
use App\Enums\Marketplace\VendorStatus;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\MarketplaceCheckoutRequest;
use App\Http\Requests\Marketplace\VendorApplicationRequest;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSetting;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\Marketplace\MarketplaceOrderHandler;
use App\Services\Payment\Stripe\StripeConnectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MarketplaceController extends Controller
{
    /** Browse active vendors with recents, category filter, trust signals, and sorting. */
    public function index(): View
    {
        $this->ensureMarketplaceEnabled();

        $sort = request()->string('sort')->toString();
        $sort = in_array($sort, ['newest', 'sales', 'name'], true) ? $sort : 'sales';

        $category = request()->string('category')->toString();
        $category = array_key_exists($category, Vendor::CATEGORIES) ? $category : null;

        // The buyer's own business is surfaced once in the pinned banner and
        // is never payable to itself, so it is excluded from the directory.
        $vendors = Vendor::query()
            ->with('user:id,first_name,last_name')
            ->withCount([
                'orders as completed_sales_count' => fn ($query) => $query->where('status', OrderStatus::RELEASED->value),
            ])
            ->where('status', VendorStatus::ACTIVE)
            ->where('user_id', '!=', auth()->id())
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when(request()->filled('search'), function ($query) {
                $query->where('display_name', 'like', '%'.request()->string('search')->toString().'%');
            })
            ->when($sort === 'sales', fn ($query) => $query->orderByDesc('completed_sales_count'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('display_name'))
            ->when($sort === 'newest', fn ($query) => $query->latest('approved_at'))
            ->paginate(12)
            ->withQueryString();

        $totalVendors = Vendor::query()
            ->where('status', VendorStatus::ACTIVE)
            ->where('user_id', '!=', auth()->id())
            ->count();

        $ownVendor      = auth()->user()->vendor;
        $ownVendorSales = $ownVendor
            ? MarketplaceOrder::query()->where('vendor_id', $ownVendor->id)->where('status', OrderStatus::RELEASED->value)->count()
            : 0;

        $recentVendors = MarketplaceOrder::query()
            ->with('vendor.user:id,first_name,last_name')
            ->where('buyer_id', auth()->id())
            ->latest()
            ->limit(30)
            ->get()
            ->pluck('vendor')
            ->filter(fn ($vendor) => $vendor && $vendor->isActive())
            ->unique('id')
            ->take(6)
            ->values();

        return view('frontend.user.marketplace.index', compact(
            'vendors', 'sort', 'category', 'totalVendors', 'ownVendor', 'ownVendorSales', 'recentVendors'
        ));
    }

    /** Payment form for a vendor. */
    public function pay(Vendor $vendor): View
    {
        $this->ensureMarketplaceEnabled();

        abort_unless($vendor->status === VendorStatus::ACTIVE, 404);

        $vendor->loadCount([
            'orders as completed_sales_count' => fn ($query) => $query->where('status', OrderStatus::RELEASED->value),
        ]);

        $wallets  = auth()->user()->activeWallets();
        $settings = MarketplaceSetting::current();

        $commissionPct = $vendor->commission_pct_override !== null
            ? (float) $vendor->commission_pct_override
            : (float) ($settings?->commission_pct ?? 0);

        $commissionFixed = (float) ($settings?->commission_fixed ?? 0);
        $minOrder        = (float) ($settings?->min_order_amount ?? 0);
        $maxOrder        = $settings?->max_order_amount !== null ? (float) $settings->max_order_amount : null;

        return view('frontend.user.marketplace.pay', compact(
            'vendor', 'wallets', 'commissionPct', 'commissionFixed', 'minOrder', 'maxOrder'
        ));
    }

    /** Execute the purchase through the configured split driver. */
    public function checkout(Vendor $vendor, MarketplaceCheckoutRequest $request, MarketplaceOrderHandler $handler): RedirectResponse
    {
        $this->ensureMarketplaceEnabled();

        $validated = $request->validated();

        $wallet = Wallet::query()
            ->with('currency')
            ->whereKey($validated['wallet_id'])
            ->where('user_id', auth()->id())
            ->first();

        if (! $wallet) {
            notifyEvs('error', __('The selected wallet does not belong to your account.'));

            return back();
        }

        try {
            $result = $handler->checkout(
                buyer: auth()->user(),
                vendor: $vendor,
                gross: (float) $validated['amount'],
                currency: $wallet->currency,
                remarks: $validated['remarks'] ?? null,
            );
        } catch (NotifyErrorException $e) {
            notifyEvs('error', $e->getMessage());

            return back()->withInput();
        }

        $order = $result->order;

        // A gateway split (Stripe Connect) is not paid yet — the buyer
        // finishes on the provider's hosted page and the order is released by
        // its webhook.
        if ($result->redirectUrl !== null) {
            return redirect()->away($result->redirectUrl);
        }

        notifyEvs('success', __('Payment of :amount :code sent to :vendor.', [
            'amount' => $order->gross_amount,
            'code'   => $wallet->currency->code,
            'vendor' => $vendor->display_name,
        ]));

        return redirect()->route('user.marketplace.orders')->with('mkt_payment_success', [
            'order_id'    => $order->id,
            'vendor_id'   => $vendor->id,
            'vendor_name' => $vendor->display_name,
            'amount'      => (float) $order->gross_amount,
            'net'         => (float) $order->vendor_net,
            'code'        => (string) $wallet->currency->code,
            'ref'         => (string) $order->trx_reference,
            'note'        => $validated['remarks'] ?? null,
            'wallet_code' => (string) $wallet->currency->code,
        ]);
    }

    /** Buyer's purchases and, when the user is a vendor, received sales. */
    public function orders(): View
    {
        $this->ensureMarketplaceEnabled();

        $purchases = MarketplaceOrder::query()
            ->with(['vendor:id,display_name', 'currency:id,code'])
            ->where('buyer_id', auth()->id())
            ->latest()
            ->paginate(10, ['*'], 'purchases_page');

        $vendor = auth()->user()->vendor;

        $sales = $vendor
            ? MarketplaceOrder::query()
                ->with(['buyer:id,first_name,last_name', 'currency:id,code'])
                ->where('vendor_id', $vendor->id)
                ->latest()
                ->paginate(10, ['*'], 'sales_page')
            : null;

        return view('frontend.user.marketplace.orders', compact('purchases', 'sales', 'vendor'));
    }

    /** Become-a-vendor application form (or current status). */
    public function apply(): View
    {
        $this->ensureMarketplaceEnabled();

        $vendor = auth()->user()->vendor;

        $connect        = app(StripeConnectService::class);
        $connectEnabled = $connect->isEnabled();
        $connectReady   = $vendor !== null && $connect->isReady($vendor);

        return view('frontend.user.marketplace.apply', compact('vendor', 'connectEnabled', 'connectReady'));
    }

    public function applyStore(VendorApplicationRequest $request): RedirectResponse
    {
        $this->ensureMarketplaceEnabled();

        if (Vendor::query()->where('user_id', auth()->id())->exists()) {
            notifyEvs('error', __('You already have a vendor profile.'));

            return redirect()->route('user.marketplace.apply');
        }

        $validated = $request->validated();

        Vendor::query()->create([
            'user_id'      => auth()->id(),
            'display_name' => $validated['display_name'],
            'about'        => $validated['about'] ?? null,
            'category'     => $validated['category'],
            'status'       => VendorStatus::PENDING,
        ]);

        notifyEvs('success', __('Your vendor application has been submitted for review.'));

        return redirect()->route('user.marketplace.apply');
    }

    private function ensureMarketplaceEnabled(): void
    {
        abort_unless((bool) (MarketplaceSetting::current()?->enabled), 404);
    }
}
