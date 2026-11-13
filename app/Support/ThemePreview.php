<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Theme;
use App\Http\Middleware\ApplyThemePreview;
use Throwable;

/**
 * A visitor's per-session look at a storefront theme other than the active
 * one, so a public demo can link every landing page from one installation.
 *
 * Only ever available on a demo install (`app.demo`). The choice lives in the
 * visitor's session and is applied to one request at a time as a request
 * attribute by {@see ApplyThemePreview}, which is attached
 * to the public route groups only. The `active_theme` setting is never
 * written, so one visitor's preview cannot reach another visitor or the admin
 * panel, whose page builder saves components against {@see activeTheme()}.
 */
final class ThemePreview
{
    public const SESSION_KEY = 'theme_preview';

    public const REQUEST_ATTRIBUTE = 'theme_preview';

    public static function isAvailable(): bool
    {
        return (bool) config('app.demo');
    }

    /**
     * The theme previewed on the current request, or null when none is.
     */
    public static function current(): ?Theme
    {
        try {
            $theme = request()->attributes->get(self::REQUEST_ATTRIBUTE);
        } catch (Throwable) {
            return null;
        }

        return $theme instanceof Theme ? $theme : null;
    }
}
