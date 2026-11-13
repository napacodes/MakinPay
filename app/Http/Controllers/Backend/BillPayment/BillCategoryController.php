<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\BillPayment;

use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\BillPayment\StoreBillCategoryRequest;
use App\Http\Requests\Backend\BillPayment\UpdateBillCategoryRequest;
use App\Models\BillCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The groups customers browse billers by. Created and edited in one modal on
 * the list, because a category is a name, a code and an icon.
 *
 * A system category (the six the module ships with) cannot be deleted and
 * keeps its code, and no category with billers can be deleted at all:
 * billers.bill_category_id is RESTRICT. Switching a category off hides its
 * billers from customers without touching them.
 */
class BillCategoryController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index'                             => 'bill-payment-list',
            'store|update|destroy|toggleStatus' => 'bill-payment-manage',
        ];
    }

    public function index(): View
    {
        $categories = BillCategory::query()
            ->withCount('billers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('backend.bill_payment.categories.index', [
            'categories' => $categories,
            'icons'      => (array) config('bill_payments.category_icons', []),
            'stats'      => [
                'total'    => $categories->count(),
                'active'   => $categories->where('status', true)->count(),
                'disabled' => $categories->where('status', false)->count(),
            ],
        ]);
    }

    public function store(StoreBillCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        BillCategory::query()->create([
            'code'        => $data['code'],
            'name'        => $data['name'],
            'icon'        => $data['icon']        ?? null,
            'description' => $data['description'] ?? null,
            'sort_order'  => (int) ($data['sort_order'] ?? ((int) BillCategory::query()->max('sort_order') + 10)),
            'status'      => (bool) ($data['status'] ?? false),
            'is_system'   => false,
        ]);

        notifyEvs('success', __('Category added.'));

        return redirect()->route('admin.bill-payment.categories.index');
    }

    public function update(UpdateBillCategoryRequest $request, BillCategory $category): RedirectResponse
    {
        $data = $request->validated();

        $category->update([
            'code'        => $category->is_system ? $category->code : $data['code'],
            'name'        => $data['name'],
            'icon'        => $data['icon']        ?? null,
            'description' => $data['description'] ?? null,
            'sort_order'  => (int) ($data['sort_order'] ?? $category->sort_order),
            'status'      => (bool) ($data['status'] ?? false),
        ]);

        notifyEvs('success', __('Category updated.'));

        return redirect()->route('admin.bill-payment.categories.index');
    }

    public function destroy(BillCategory $category): RedirectResponse
    {
        if ($category->is_system) {
            notifyEvs('error', __('This category ships with Bill payments and cannot be deleted. Disable it instead.'));

            return redirect()->back();
        }

        if ($category->billers()->exists()) {
            notifyEvs('error', __('This category has billers. Move them to another category first, or disable it instead.'));

            return redirect()->back();
        }

        $category->delete();

        notifyEvs('success', __('Category deleted.'));

        return redirect()->route('admin.bill-payment.categories.index');
    }

    public function toggleStatus(BillCategory $category): RedirectResponse
    {
        $category->forceFill(['status' => ! $category->status])->save();

        notifyEvs('success', $category->status
            ? __('Category enabled.')
            : __('Category disabled. Its billers are hidden from customers.'));

        return redirect()->back();
    }
}
