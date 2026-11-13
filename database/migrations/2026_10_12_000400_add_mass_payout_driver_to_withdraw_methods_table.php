<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which payout driver a withdraw method sends mass payouts through: manual,
 * bitnob or stripe_connect.
 *
 * Null is the upgrade default and means the method is not offered for mass
 * payouts at all, so an existing installation offers nothing new until the
 * admin opts a method in. Ordinary withdrawals never read this column.
 *
 * Guarded so a second run is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'withdraw_methods';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'mass_payout_driver')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('mass_payout_driver', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'mass_payout_driver')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('mass_payout_driver');
        });
    }
};
