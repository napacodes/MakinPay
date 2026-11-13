<?php

use App\Models\MarketplaceSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turn the vendor marketplace on by default. The create-table migration
     * seeds a disabled settings row, so both fresh installs and existing
     * deployments are flipped to enabled here; admins can still disable it
     * afterwards from the marketplace settings screen.
     */
    public function up(): void
    {
        if (DB::table('marketplace_settings')->exists()) {
            DB::table('marketplace_settings')->update([
                'enabled'    => true,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('marketplace_settings')->insert([
                'enabled'             => true,
                'commission_pct'      => 5.0000,
                'commission_fixed'    => 0,
                'min_order_amount'    => 1,
                'max_order_amount'    => null,
                'auto_release'        => true,
                'payout_hold_minutes' => 0,
                'commission_user_id'  => null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        MarketplaceSetting::flushCache();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('marketplace_settings')->exists()) {
            DB::table('marketplace_settings')->update([
                'enabled'    => false,
                'updated_at' => now(),
            ]);
        }

        MarketplaceSetting::flushCache();
    }
};
