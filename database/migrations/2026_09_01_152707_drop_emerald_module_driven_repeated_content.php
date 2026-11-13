<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hide the repeatable-content panel on the Emerald theme's Wallet Earn and
 * Plans components. The rows themselves are preserved.
 *
 * Both sections originally shipped their figures as seeded copy. They now read
 * live from WalletEarnPlan and SubscriptionPlan, so those rows render nowhere —
 * but EmeraldThemeComponentSeeder::mergeContentData() only ever *adds* keys, so
 * replaying the seeder cannot clear them. An installation that ran the earlier
 * version is left with orphaned rows and a `repeated_content` flag that still
 * makes the page builder offer an administrator fields nothing renders.
 *
 * Scoped strictly to `theme = 'emerald'`: Classic and Golden keep their own
 * component rows untouched.
 *
 * Idempotent — a second run finds nothing to do, so it is equally safe on a
 * fresh install where the current seeder never created the rows at all.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const MODULE_DRIVEN_KEYS = ['wallet_earn', 'subscription_plans'];

    public function up(): void
    {
        if (! Schema::hasTable('page_components') || ! Schema::hasTable('page_component_repeated_contents')) {
            return;
        }

        $components = DB::table('page_components')
            ->whereIn('component_key', self::MODULE_DRIVEN_KEYS)
            ->when(
                Schema::hasColumn('page_components', 'theme'),
                fn ($query) => $query->where('theme', 'emerald'),
                // With no theme column there is no Emerald row to target, and
                // an unscoped delete would hit Classic's components instead.
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->pluck('id');

        if ($components->isEmpty()) {
            return;
        }

        DB::table('page_components')
            ->whereIn('id', $components)
            ->update(['repeated_content' => 0]);
    }

    /**
     * Reversible: the rows were never removed, so restoring the flag restores
     * the previous state exactly. Only components that still hold rows are
     * flagged, so a component that never had repeatable content is not given a
     * panel it never had.
     */
    public function down(): void
    {
        if (! Schema::hasTable('page_components') || ! Schema::hasTable('page_component_repeated_contents')) {
            return;
        }

        DB::table('page_components')
            ->whereIn('component_key', self::MODULE_DRIVEN_KEYS)
            ->when(
                Schema::hasColumn('page_components', 'theme'),
                fn ($query) => $query->where('theme', 'emerald'),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->whereIn('id', DB::table('page_component_repeated_contents')->select('component_id'))
            ->update(['repeated_content' => 1]);
    }
};
