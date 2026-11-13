<?php

declare(strict_types=1);

use App\Services\FeatureManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets, vaults and scheduled payments were added to
 * config/feature_catalog.php with the mobile API, after existing installs
 * had seeded the `features` table. A catalog key with no row is denied as
 * disabled, so on every upgraded site the feature gate answered 404 for
 * all three and the Feature Management screen never showed their toggles.
 * Mirrors the remittance and vendor marketplace sync migrations.
 *
 * Idempotent and deploy-safe: sync() inserts only missing rows via
 * firstOrNew() and never overwrites a toggle the administrator has set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('features') || ! Schema::hasTable('feature_access_rules')) {
            return;
        }

        $manager = app(FeatureManager::class);
        $manager->sync();
        $manager->flush();
    }

    public function down(): void
    {
        /**
         * Intentionally a no-op: the feature rows are admin-managed runtime
         * data, not schema.
         */
    }
};
