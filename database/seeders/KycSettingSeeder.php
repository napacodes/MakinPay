<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the KYC Verification settings rows without touching existing values.
 *
 * seedDefaultSettings() only runs on a fresh install. A customer upgrading into
 * admin-controlled KYC automation would otherwise see the settings screen render
 * fields with no row behind them. Every read already passes a default (the
 * legacy environment flag), so a missing row is harmless — this seeder is what
 * makes the switch editable rather than merely defaulted.
 *
 * Additive by construction: a key that already exists is left exactly as the
 * administrator set it.
 */
class KycSettingSeeder extends Seeder
{
    /**
     * Read from config/settings.php rather than repeated here, so the defaults
     * cannot drift away from the fields the settings screen renders.
     */
    private const SECTION = 'kyc_settings';

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

        Setting::flushCache();
    }
}
