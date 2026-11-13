<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the dashboard SLA rows without touching existing values.
 *
 * seedDefaultSettings() only runs during a fresh install, so a customer who
 * updates into the redesigned dashboard would otherwise see the settings
 * screen render fields with no row behind them. Every read of an SLA target
 * passes its default, so a missing row is harmless rather than broken — this
 * seeder is what makes the targets editable instead of merely defaulted.
 *
 * Additive by construction: a key that already exists is left exactly as the
 * administrator set it, which is why it is safe in reconcile_seeders.
 */
class DashboardSettingSeeder extends Seeder
{
    /**
     * Read from config/settings.php rather than repeated here, so the defaults
     * cannot drift away from the fields the settings screen renders.
     */
    private const SECTION = 'dashboard_settings';

    public function run(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ((array) config('settings.'.self::SECTION.'.elements', []) as $element) {
            $key = $element['key'] ?? null;

            if (! is_string($key) || $key === '' || Setting::has($key)) {
                continue;
            }

            Setting::add($key, $element['value'] ?? '', (string) ($element['data'] ?? 'string'));
        }
    }
}
