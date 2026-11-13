<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Theme;
use App\Support\ThemePreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a visitor's previewed theme to the current request.
 *
 * Attached to the public route groups (web.php, auth.php) and never to the
 * admin group, so the admin panel always works against the real active theme.
 */
class ApplyThemePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ThemePreview::isAvailable() && $request->hasSession()) {
            $theme = Theme::tryFrom((string) $request->session()->get(ThemePreview::SESSION_KEY, ''));

            if ($theme instanceof Theme) {
                $request->attributes->set(ThemePreview::REQUEST_ATTRIBUTE, $theme);
            }
        }

        return $next($request);
    }
}
