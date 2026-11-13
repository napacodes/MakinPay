<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\Marketplace;

use App\Enums\Marketplace\OrderStatus;
use App\Enums\Marketplace\VendorStatus;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Marketplace\VendorSuspendRequest;
use App\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index'           => 'marketplace-vendor-list',
            'approve|suspend' => 'marketplace-vendor-manage',
        ];
    }

    public function index(Request $request): View
    {
        $vendors      = $this->vendorsQuery($request);
        $statusCounts = $this->statusCounts();

        return view('backend.marketplace.vendors.index', compact('vendors', 'statusCounts'));
    }

    public function approve(Vendor $vendor): RedirectResponse
    {
        $vendor->update([
            'status'         => VendorStatus::ACTIVE,
            'approved_at'    => $vendor->approved_at ?? now(),
            'suspended_at'   => null,
            'suspend_reason' => null,
        ]);

        return back()->with('notifyevs', ['type' => 'success', 'message' => __(':vendor is now active.', ['vendor' => $vendor->display_name])]);
    }

    public function suspend(VendorSuspendRequest $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update([
            'status'         => VendorStatus::SUSPENDED,
            'suspended_at'   => now(),
            'suspend_reason' => $request->validated('reason'),
        ]);

        return back()->with('notifyevs', ['type' => 'success', 'message' => __(':vendor has been suspended.', ['vendor' => $vendor->display_name])]);
    }

    private function vendorsQuery(Request $request): LengthAwarePaginator
    {
        $status = $request->string('status')->toString();

        return Vendor::query()
            ->with('user:id,first_name,last_name,email')
            ->withCount([
                'orders as completed_sales_count' => fn ($q) => $q->where('status', OrderStatus::RELEASED->value),
            ])
            ->when(in_array($status, ['pending', 'active', 'suspended'], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(fn ($inner) => $inner
                    ->where('display_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @return array{all: int, pending: int, active: int, suspended: int}
     */
    private function statusCounts(): array
    {
        $counts = Vendor::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'all'       => (int) $counts->sum(),
            'pending'   => (int) $counts->get(VendorStatus::PENDING->value, 0),
            'active'    => (int) $counts->get(VendorStatus::ACTIVE->value, 0),
            'suspended' => (int) $counts->get(VendorStatus::SUSPENDED->value, 0),
        ];
    }
}
