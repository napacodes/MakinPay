<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Writes the platform's own settings rows so a fresh install has a public
 * site that looks like a product rather than a half-built one.
 *
 * Before this existed, `makinpay:install --seed-baseline` created **zero**
 * rows in `settings`. Every key the site reads therefore fell back to its
 * `config/settings.php` default, and two of those defaults were never meant
 * to be user-visible:
 *
 *  - `copyright_text` defaults to `'admin'` (a leftover from the demo dump),
 *    so a brand-new install rendered a footer that read, in full, "admin".
 *  - `site_title` defaults to `'Coevs'`, the previous product's name, so the
 *    browser tab and outgoing mail named someone else's product.
 *
 * The legacy `DB/digikash.sql` dump shipped 40 real rows for the same keys.
 * The CLI installer replaced that import but nothing replaced the rows.
 *
 * Scope: only keys that are (a) public-facing and (b) not secrets. Mail
 * credentials, SMTP hosts, and API secrets are deliberately absent — they are
 * per-install values an operator sets in the admin panel, not defaults, and
 * writing them here would put placeholder credentials in every install.
 *
 * Additive by construction (`Setting::has()` short-circuit, exactly like the
 * other setting seeders), so it is safe in `reconcile_seeders` too: a key an
 * administrator has edited is never overwritten.
 */
class SystemSettingSeeder extends Seeder
{
    /**
     * Keys taken from `active_theme`'s section: the public site shell.
     *
     * Values are read from `config/settings.php` so the seeded default and the
     * field the settings screen renders cannot drift apart.
     */
    private const SECTIONS = [
        'general_settings',
    ];

    /**
     * The public-facing keys this seeder owns. Listed explicitly rather than
     * seeding a whole section, because "general_settings" also contains
     * brand asset paths (`logo`, `login_banner`) that are per-install uploads.
     */
    private const PUBLIC_KEYS = [
        'site_title',
        'site_decimal',
        'preloader_text',
        'admin_prefix',
        'site_timezone',
        'home_redirect',
        'support_email',
        'support_phone',
        'copyright_text',
    ];

    public function run(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::SECTIONS as $section) {
            foreach ((array) config('settings.'.$section.'.elements', []) as $element) {
                $key = $element['key'] ?? null;

                if (! is_string($key) || $key === '' || ! in_array($key, self::PUBLIC_KEYS, true)) {
                    continue;
                }

                if (Setting::has($key)) {
                    continue;
                }

                $value = $element['value'] ?? '';

                // A `select` field's option list is sometimes a runtime-built
                // array rather than a stored value (`[]`). Persisting that
                // raises "Array to string conversion", so skip it — the
                // reader's own default applies until an operator picks one.
                if (! is_scalar($value) && $value !== null) {
                    continue;
                }

                Setting::add($key, $value ?? '', (string) ($element['data'] ?? 'string'));
            }
        }

        Setting::flushCache();
    }
}
