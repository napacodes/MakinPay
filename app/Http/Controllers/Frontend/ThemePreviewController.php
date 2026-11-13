<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Theme;
use App\Http\Controllers\Controller;
use App\Support\ThemePreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/theme-preview/{theme}` — the shareable link a demo listing points at.
 * Remembers the theme for this visitor's session and opens the home page in
 * it. Not found on anything but a demo install.
 */
class ThemePreviewController extends Controller
{
    public function __invoke(Request $request, string $theme): RedirectResponse
    {
        abort_unless(ThemePreview::isAvailable(), 404);

        $resolved = Theme::tryFrom($theme);

        abort_unless($resolved instanceof Theme, 404);

        $request->session()->put(ThemePreview::SESSION_KEY, $resolved->value);

        return redirect()->route('home');
    }
}
