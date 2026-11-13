<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Theme;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-point every page's component list at the incoming theme's own components.
 *
 * `Page::getComponentsAttribute()` resolves purely by `component_ids` — it has
 * no theme filter — so a page composed under one theme keeps rendering that
 * theme's rows after a switch, while the active theme decides which section
 * views are used. The new theme's views therefore receive the old theme's
 * `content_data`. Classic's sections read keys directly (`$data['hero_main_image']`),
 * and a theme-only block such as `p2p_escrow` has no view outside its own theme,
 * so the mismatch surfaces as a 500 on the public landing page.
 *
 * Per-theme memory makes the switch non-destructive. Before remapping, each
 * page's current list is snapshotted against the theme it was composed for.
 * Switching back restores that snapshot verbatim, so blocks the other theme
 * has no equivalent for — Classic's `about`, `team`, `contact` and the rest —
 * come back exactly as the administrator arranged them. Without the snapshot a
 * round trip would silently shed every unmatched block, because a key-based
 * remap cannot re-derive what the intermediate list no longer references.
 *
 * With no snapshot yet (the first switch, or a page created since), the list is
 * derived by matching `component_key`, the stable identity across themes, in
 * the page's existing order; blocks the incoming theme does not provide are
 * dropped rather than left dangling.
 *
 * Only `pages.component_ids` is rewritten, plus the snapshot rows in settings.
 * No component row is created, changed or removed.
 */
class ThemePageComposer
{
    /**
     * Remap every page onto the given theme's components.
     *
     * @return int the number of pages whose component list changed
     */
    public function recompose(Theme $theme): int
    {
        if (! Schema::hasTable('pages') || ! Schema::hasTable('page_components')) {
            return 0;
        }

        if (! Schema::hasColumn('page_components', 'theme')) {
            return 0;
        }

        $target = $this->componentIdsByKey($theme);

        if ($target === []) {
            return 0;
        }

        $keyById   = DB::table('page_components')->pluck('component_key', 'id');
        $themeById = DB::table('page_components')->pluck('theme', 'id');
        $changed   = 0;

        foreach (DB::table('pages')->get(['id', 'component_ids']) as $page) {
            $current = json_decode((string) $page->component_ids, true);

            if (! is_array($current) || $current === []) {
                continue;
            }

            $current = array_map('strval', $current);

            $this->remember($this->themeOf($current, $themeById), (int) $page->id, $current);

            $remapped = $this->recall($theme, (int) $page->id, $target)
                ?? $this->derive($current, $keyById, $target);

            // Never blank a page out, and never write an identical list.
            if ($remapped === [] || $remapped === $current) {
                continue;
            }

            DB::table('pages')->where('id', $page->id)->update([
                'component_ids' => json_encode($remapped),
            ]);

            $changed++;
        }

        return $changed;
    }

    /**
     * The component list a page would have under the given theme, worked out
     * the same way {@see recompose()} would — snapshot first, key match second —
     * but read-only: nothing in `pages` or `settings` is written. A theme
     * preview renders a theme without activating it, and must not leave a
     * snapshot behind that a real switch would later restore.
     *
     * @param  list<int|string> $current
     * @return list<string>
     */
    public function componentIdsFor(Theme $theme, int $pageId, array $current): array
    {
        $current = array_values(array_map('strval', $current));

        if ($current === [] || ! Schema::hasColumn('page_components', 'theme')) {
            return $current;
        }

        $themeById = DB::table('page_components')->whereIn('id', $current)->pluck('theme', 'id');

        if ($this->themeOf($current, $themeById) === $theme) {
            return $current;
        }

        $target = $this->componentIdsByKey($theme);

        if ($target === []) {
            return [];
        }

        $keyById = DB::table('page_components')->whereIn('id', $current)->pluck('component_key', 'id');

        return $this->recall($theme, $pageId, $target) ?? $this->derive($current, $keyById, $target);
    }

    /**
     * Give a page a starting composition for a theme it has never been shown
     * in, so the first switch to that theme restores a designed order instead
     * of deriving one by key — which can only carry over blocks the previous
     * theme also had, and so would drop every block unique to the new theme.
     *
     * Never overwrites: an existing snapshot is the administrator's own
     * arrangement from an earlier visit to the theme, and wins.
     *
     * @param  list<int|string> $ids
     * @return bool             whether a snapshot was written
     */
    public function seedComposition(Theme $theme, int $pageId, array $ids): bool
    {
        $ids = array_values(array_map('strval', $ids));

        if ($ids === [] || setting($this->snapshotKey($theme, $pageId), '') !== '') {
            return false;
        }

        setting([$this->snapshotKey($theme, $pageId), json_encode($ids)]);

        return true;
    }

    /**
     * Which theme a page is currently composed for, read from the components it
     * actually references rather than from `active_theme` — by the time this
     * runs the setting already names the incoming theme.
     *
     * @param list<string> $ids
     * @param Collection   $themeById
     */
    private function themeOf(array $ids, $themeById): ?Theme
    {
        foreach ($ids as $id) {
            $theme = Theme::tryFrom((string) ($themeById[(int) $id] ?? ''));

            if ($theme instanceof Theme) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * @param list<string> $ids
     */
    private function remember(?Theme $theme, int $pageId, array $ids): void
    {
        if (! $theme instanceof Theme) {
            return;
        }

        setting([$this->snapshotKey($theme, $pageId), json_encode($ids)]);
    }

    /**
     * A snapshot is only usable while every id in it still exists and still
     * belongs to that theme; a component deleted since would otherwise be
     * restored as a dangling reference.
     *
     * @param  array<string, int> $target
     * @return list<string>|null
     */
    private function recall(Theme $theme, int $pageId, array $target): ?array
    {
        $stored = json_decode((string) setting($this->snapshotKey($theme, $pageId), ''), true);

        if (! is_array($stored) || $stored === []) {
            return null;
        }

        $valid = array_map('strval', $this->componentIdsForTheme($theme));
        $ids   = array_map('strval', $stored);

        foreach ($ids as $id) {
            if (! in_array($id, $valid, true)) {
                return null;
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>       $current
     * @param  Collection         $keyById
     * @param  array<string, int> $target
     * @return list<string>
     */
    private function derive(array $current, $keyById, array $target): array
    {
        $remapped = [];

        foreach ($current as $id) {
            $key = $keyById[(int) $id] ?? null;

            // Unknown id, or a block this theme does not provide: drop it
            // rather than keep a reference that cannot render.
            if ($key === null || ! isset($target[$key])) {
                continue;
            }

            $remapped[] = (string) $target[$key];
        }

        return array_values(array_unique($remapped));
    }

    private function snapshotKey(Theme $theme, int $pageId): string
    {
        return "page_composition_{$theme->value}_{$pageId}";
    }

    /**
     * Every component id the theme owns, whatever its key. Administrator-made
     * blocks are keyed by a slug of their name with no uniqueness check, so a
     * key can map to several ids; a snapshot must be judged against the ids
     * themselves or a page with two "Promo" blocks loses its composition on
     * the way back.
     *
     * @return list<int>
     */
    private function componentIdsForTheme(Theme $theme): array
    {
        return DB::table('page_components')
            ->where('theme', $theme->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, int> component_key => id, for one theme
     */
    private function componentIdsByKey(Theme $theme): array
    {
        return DB::table('page_components')
            ->where('theme', $theme->value)
            ->orderBy('id')
            ->pluck('id', 'component_key')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
