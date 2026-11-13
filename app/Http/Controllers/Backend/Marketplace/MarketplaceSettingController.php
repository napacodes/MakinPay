<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\Marketplace;

use App\Enums\SystemAccountType;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Marketplace\MarketplaceSettingRequest;
use App\Http\Requests\Marketplace\ProvisionMarketplaceCommissionAccountRequest;
use App\Models\MarketplaceSetting;
use App\Models\MarketplaceSettingRevision;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Marketplace\MarketplaceCommissionAccountService;
use App\Services\Marketplace\Split\SplitProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MarketplaceSettingController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'edit|update|provisionCommissionAccount' => 'marketplace-manage',
        ];
    }

    public function edit(): View
    {
        $settings = MarketplaceSetting::query()->first() ?? MarketplaceSetting::query()->create([]);

        $commissionUser = $settings->commission_user_id
            ? User::query()->find($settings->commission_user_id)
            : null;
        $commissionAccountIsProtected = $commissionUser?->isSystemAccount(SystemAccountType::MARKETPLACE_COMMISSION) ?? false;

        $splitProviders = app(SplitProviderFactory::class)->options();

        $vendorOverrideCount = Vendor::query()->whereNotNull('commission_pct_override')->count();

        /*
         * The only settings history this application keeps. A revision records
         * a change of the account that receives commission — nothing writes a
         * per-field log, so the section is named for what it actually holds.
         */
        $revisions = MarketplaceSettingRevision::query()
            ->with('admin:id,name')
            ->latest('created_at')
            ->limit(25)
            ->get();

        return view('backend.marketplace.settings', compact('settings', 'commissionUser', 'commissionAccountIsProtected', 'splitProviders', 'vendorOverrideCount', 'revisions'));
    }

    public function update(MarketplaceSettingRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $settings = MarketplaceSetting::query()->first() ?? MarketplaceSetting::query()->create([]);

        $settings->update([
            'enabled'          => (bool) $validated['enabled'],
            'split_provider'   => $validated['split_provider'] ?? $settings->split_provider ?? 'wallet',
            'commission_pct'   => (float) $validated['commission_pct'],
            'commission_fixed' => (float) ($validated['commission_fixed'] ?? 0),
            'min_order_amount' => (float) ($validated['min_order_amount'] ?? 0),
            'max_order_amount' => ($validated['max_order_amount'] ?? null) !== null && $validated['max_order_amount'] !== ''
                ? (float) $validated['max_order_amount']
                : null,
            'auto_release'        => (bool) $validated['auto_release'],
            'payout_hold_minutes' => (int) ($validated['payout_hold_minutes'] ?? 0),
        ]);

        return back()->with('notifyevs', ['type' => 'success', 'message' => __('Marketplace settings updated')]);
    }

    public function provisionCommissionAccount(
        ProvisionMarketplaceCommissionAccountRequest $request,
        MarketplaceCommissionAccountService $accounts,
    ): RedirectResponse {
        $account = $accounts->provisionAndActivate($request->user('admin')?->id);

        return back()->with('notifyevs', [
            'type'    => 'success',
            'message' => __('Protected commission account #:id is active for future orders.', ['id' => $account->id]),
        ]);
    }
}
