<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MarketplaceSetting;
use App\Services\Marketplace\MarketplaceCommissionAccountService;
use Illuminate\Database\Seeder;

class MarketplaceSettingSeeder extends Seeder
{
    /**
     * Seed the marketplace settings row and guarantee the protected
     * commission account behind it.
     *
     * A commission-bearing checkout fails closed unless
     * `marketplace_settings.commission_user_id` points at a user carrying
     * `SystemAccountType::MARKETPLACE_COMMISSION`. That pointer used to be
     * set only by an administrator pressing "Create protected account" on the
     * marketplace settings screen, so an installation that never visited that
     * screen — every upgrade from a build that stored a personal user id, and
     * every fresh install — rejected each commissioned order with "The
     * protected marketplace commission account is not configured. Please
     * contact support." while the settings row itself looked perfectly valid.
     *
     * Provisioning is idempotent: the service reuses the protected account
     * when one already exists and writes a revision only when the pointer
     * actually moves, so replaying this seeder on every update is safe. A
     * legacy personal account is repointed rather than preserved because the
     * custody rules reject it outright; its existing balance is never moved.
     */
    public function run(): void
    {
        if (! MarketplaceSetting::query()->exists()) {
            MarketplaceSetting::query()->create([
                'enabled'             => false,
                'commission_pct'      => 5.0000,
                'commission_fixed'    => 0,
                'min_order_amount'    => 1,
                'max_order_amount'    => null,
                'auto_release'        => true,
                'payout_hold_minutes' => 0,
                'commission_user_id'  => null,
            ]);
        }

        app(MarketplaceCommissionAccountService::class)->provisionAndActivate(null);
    }
}
