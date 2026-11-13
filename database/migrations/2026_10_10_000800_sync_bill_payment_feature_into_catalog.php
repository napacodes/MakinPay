<?php

declare(strict_types=1);

use App\Services\FeatureManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `bill_payment` key from config/feature_catalog.php to the
 * `features` table on existing installs. A catalog key with no row is denied
 * as disabled, so without this the Feature Management screen would not show
 * its toggle until the next reconcile. Mirrors the savings sync migration.
 *
 * The key ships switched off (`is_enabled => false`): billers are configured
 * first and the admin turns the module on when ready.
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
