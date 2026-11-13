<?php

declare(strict_types=1);

use App\Services\FeatureManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantees the "vendor_marketplace" feature is present in Feature
 * Management on every environment. Mirrors the remittance sync migration:
 * the catalog entry was added to config/feature_catalog.php after existing
 * installs had already seeded the `features` table, so without a sync the
 * feature gate middleware would 404 every marketplace route and the admin
 * Feature Management screen would never show the toggle.
 *
 * Idempotent and deploy-safe: sync() inserts only missing rows via
 * firstOrNew() and never overwrites admin-controlled toggles; flush()
 * clears the cached catalog so the row appears immediately.
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
         * Intentionally a no-op: the feature row is admin-managed runtime
         * data — removing it is an explicit Feature Management action, not
         * a schema rollback concern.
         */
    }
};
