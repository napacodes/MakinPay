<?php

namespace App\Http\Controllers\Backend;

use App\Enums\Theme;
use App\Http\Requests\Backend\ThemeColorRequest;
use App\Models\Page;
use App\Models\PageComponent;
use App\Models\Setting;
use App\Services\ThemePageComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Theme Manager — flips the site-wide `active_theme` setting between Classic
 * and Golden. The page builder and frontend layout both read this value via
 * {@see activeTheme()} so the entire surface follows in lock-step.
 */
class ThemeManagerController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index'                    => 'theme-manager-view',
            'activate'                 => 'theme-manager-update',
            'updateColors|resetColors' => 'theme-manager-update',
        ];
    }

    public function index(): View
    {
        $activeTheme = activeTheme();

        $themes = collect(Theme::cases())->map(function (Theme $theme) use ($activeTheme) {
            return [
                'value'           => $theme->value,
                'label'           => $theme->label(),
                'tagline'         => $theme->tagline(),
                'mode_label'      => $theme->modeLabel(),
                'design_spec'     => $theme->designSpec(),
                'accent_color'    => $theme->accentColor(),
                'is_dark'         => $theme->isDark(),
                'has_light_mode'  => in_array('light', $theme->defaultModeOptions(), true),
                'is_active'       => $theme === $activeTheme,
                'component_count' => PageComponent::query()
                    ->forTheme($theme)
                    ->count(),
            ];
        });

        $palettes = collect(Theme::cases())->map(function (Theme $theme): array {
            return [
                'value'        => $theme->value,
                'label'        => $theme->label(),
                'accent_color' => $theme->accentColor(),
                'modes'        => $theme->defaultModeOptions(),
                'mode'         => $theme->defaultMode(),
                'slots'        => collect($theme->colorSlots())
                    ->map(fn (array $slot): array => $slot + ['value' => $theme->colorValue($slot['key'])])
                    ->all(),
            ];
        });

        return view('backend.theme-manager.index', [
            'themes'      => $themes,
            'activeTheme' => $activeTheme,
            'palettes'    => $palettes,
            'headerStats' => $this->headerStats($themes, $activeTheme),
        ]);
    }

    /**
     * Summary values for the shared admin page header.
     *
     * Derived from the same collection the cards render from, so the header
     * can never describe a different theme than the grid underneath it.
     *
     * @param  Collection<int, array<string, mixed>>     $themes
     * @return list<array{label: string, value: string}>
     */
    private function headerStats(Collection $themes, Theme $activeTheme): array
    {
        $activeBlocks = $themes->firstWhere('is_active', true)['component_count'] ?? 0;

        return [
            ['label' => __('Active'), 'value' => $activeTheme->label()],
            ['label' => __('Themes'), 'value' => number_format($themes->count())],
            ['label' => __('Blocks'), 'value' => number_format((int) $activeBlocks)],
            ['label' => __('Mode'), 'value' => $activeTheme->modeLabel()],
        ];
    }

    /**
     * Persist the landing-page color palette for a single theme.
     */
    public function updateColors(ThemeColorRequest $request, string $theme): RedirectResponse|JsonResponse
    {
        $resolved = Theme::tryFrom($theme);

        if ($resolved === null) {
            return $this->respond($request, 'error', __('Unknown theme.'));
        }

        $submitted = (array) $request->validated()['colors'];

        foreach ($resolved->colorSlots() as $slot) {
            $value = $submitted[$slot['key']] ?? null;

            if (is_string($value)) {
                setting([$resolved->colorSettingKey($slot['key']), strtoupper($value)]);
            }
        }

        $mode = $request->validated()['default_mode'] ?? null;

        if (is_string($mode) && $resolved->defaultModeOptions() !== []) {
            setting([$resolved->defaultModeSettingKey(), $mode]);
        }

        return $this->respond(
            $request,
            'success',
            __(':theme landing colors saved.', ['theme' => $resolved->label()]),
            ['palette' => $resolved->resolvedPalette()],
        );
    }

    /**
     * Answer the classic redirect, or JSON when the screen asks for it.
     *
     * The Landing Colours panel drives save/reset through fetch so it can show
     * the saving → saved → error phases the design calls for. It posts to the
     * same routes the plain forms do; only the response shape differs, so the
     * no-JavaScript path is untouched.
     *
     * @param array<string, mixed> $payload
     */
    private function respond(Request $request, string $level, string $message, array $payload = []): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(
                ['status' => $level, 'message' => $message] + $payload,
                $level === 'error' ? 422 : 200
            );
        }

        notifyEvs($level, $message);

        return redirect()->route('admin.theme-manager.index');
    }

    /**
     * Clear the saved palette for a theme, reverting it to the enum defaults.
     */
    public function resetColors(Request $request, string $theme): RedirectResponse|JsonResponse
    {
        $resolved = Theme::tryFrom($theme);

        if ($resolved === null) {
            return $this->respond($request, 'error', __('Unknown theme.'));
        }

        foreach ($resolved->colorSlots() as $slot) {
            Setting::remove($resolved->colorSettingKey($slot['key']));
        }

        if ($resolved->defaultModeOptions() !== []) {
            Setting::remove($resolved->defaultModeSettingKey());
        }

        return $this->respond(
            $request,
            'success',
            __(':theme landing colors reset to defaults.', ['theme' => $resolved->label()]),
            ['palette' => $resolved->resolvedPalette()],
        );
    }

    public function activate(Request $request, string $theme): RedirectResponse|JsonResponse
    {
        $resolved = Theme::tryFrom($theme);

        if ($resolved === null) {
            return $this->respond($request, 'error', __('Unknown theme.'));
        }

        if ($resolved === activeTheme()) {
            return $this->respond($request, 'info', __(':theme is already active.', ['theme' => $resolved->label()]));
        }

        setting(['active_theme', $resolved->value]);

        // Pages keep pointing at the previous theme's component rows, which the
        // new theme's views cannot render. Remap them by component_key first.
        app(ThemePageComposer::class)->recompose($resolved);

        // Page components / page slug caches are theme-sensitive — flush them
        // so the next request reflects the new pool of available blocks.
        $this->flushPageCaches();

        return $this->respond(
            $request,
            'success',
            __(':theme theme activated. Pages now render with this look.', ['theme' => $resolved->label()]),
            ['active' => $resolved->value],
        );
    }

    /**
     * Clear every cached Page/PageComponent payload so the new theme's
     * component pool & layout are picked up immediately.
     */
    protected function flushPageCaches(): void
    {
        $slugs   = DB::table('pages')->pluck('slug')->all();
        $pageIds = DB::table('pages')->pluck('id')->all();

        foreach ($slugs as $slug) {
            Cache::forget('page_slug_'.md5((string) $slug));
        }
        foreach ($pageIds as $id) {
            Cache::forget("page_components_{$id}");
        }
        Cache::forget('slugs_list');
    }
}
