<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Media & Uploads settings rows without touching existing values.
 *
 * seedDefaultSettings() only runs on a fresh install, so a customer upgrading
 * into admin-controlled upload limits would see the settings screen render
 * fields with no row behind them. Every reader already falls back to the
 * previous hardcoded list, so a missing row is harmless — this seeder is what
 * makes the values editable rather than merely defaulted.
 *
 * `max_upload_size` predates this section and is deliberately included: the
 * key is unchanged, so an installation that already has a row keeps whatever
 * the administrator set, and one that somehow lacks it finally gets a value.
 *
 * Additive by construction: a key that already exists is left alone.
 */
class MediaSettingSeeder extends Seeder
{
    private const SECTION = 'media_settings';

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
