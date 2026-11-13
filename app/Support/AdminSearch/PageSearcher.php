<?php

declare(strict_types=1);

namespace App\Support\AdminSearch;

use App\Services\FeatureManager;
use App\Support\AdminSidebarIndicatorManager;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The admin's own screens, read from the same menu config the sidebar draws.
 *
 * This is the flattening the old header search endpoint did inline, pulled
 * out so the two callers — the legacy menu-search route and the palette —
 * cannot drift apart on what counts as a page. The palette's answer is the
 * stricter one: it drops every screen this admin cannot open and every
 * module the site has switched off, so a result is always somewhere the
 * operator can actually go.
 */
final class PageSearcher implements Searcher
{
    public const KEY = 'pages';

    /**
     * @param list<string> $permissions the admin's granted permission names
     */
    public function __construct(private readonly array $permissions) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Pages');
    }

    public function permission(): ?string
    {
        return null;
    }

    /**
     * @return list<array{type: string, id: string, title: string, sub: string, url: string, icon: string|null, initials: string|null, badge: string|null, tone: string}>
     */
    public function search(string $query, int $limit): array
    {
        $needle   = trim($query);
        $features = app(FeatureManager::class);
        $badges   = AdminSidebarIndicatorManager::build($this->permissions);
        $items    = [];

        foreach (self::flatten() as $entry) {
            if (! $this->allowed($entry['permission']) || ! self::enabled($features, $entry['feature_key'])) {
                continue;
            }

            /* With nothing typed, the top-level screens are the shortcuts. */
            if ($needle === '' && ! $entry['single']) {
                continue;
            }

            $title = __($entry['label']);
            $trail = $entry['single']
                ? [__($entry['section'])]
                : [__($entry['parent']), __($entry['section'])];

            if ($needle !== '' && ! self::matches($needle, [$title, ...$trail])) {
                continue;
            }

            $badge = $badges[$entry['badge'] ?? ''] ?? null;

            $items[] = [
                'type'     => 'page',
                'id'       => 'page:'.$entry['route'],
                'title'    => $title,
                'sub'      => implode(' › ', $trail),
                'url'      => $entry['url'],
                'icon'     => self::iconUrl($entry['icon']),
                'initials' => null,
                'badge'    => $badge === null ? null : self::badgeText($badge),
                'tone'     => $badge === null ? 'neutral' : self::tone((string) $badge['tone']),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Every reachable menu entry, in config order.
     *
     * The legacy endpoint returns these keys verbatim, which is why the shape
     * is kept rather than tidied: `section` is the section for a top-level
     * entry and the group for a nested one, with the section then arriving as
     * `parent`. The palette reads `single` to tell the two apart.
     *
     * @return list<array{label: string, route: string, url: string, icon: string, params: array<string, mixed>|null, permission: string|null, section: string, parent?: string, badge: string|null, feature_key: string|null, single: bool}>
     */
    public static function flatten(): array
    {
        $entries = [];

        foreach (config('admin_menus', []) as $section) {
            if (! is_array($section) || ! isset($section['menus'])) {
                continue;
            }

            $sectionLabel = (string) ($section['label'] ?? 'General');

            foreach ($section['menus'] as $menu) {
                if (($menu['type'] ?? null) === 'single' && isset($menu['route'])) {
                    $url = self::url((string) $menu['route'], $menu['params'] ?? []);

                    if ($url === null) {
                        continue;
                    }

                    $entries[] = [
                        'label'       => (string) $menu['label'],
                        'route'       => (string) $menu['route'],
                        'url'         => $url,
                        'icon'        => (string) ($menu['icon'] ?? 'cil-puzzle'),
                        'params'      => $menu['params']     ?? null,
                        'permission'  => $menu['permission'] ?? $menu['can'] ?? null,
                        'section'     => $sectionLabel,
                        'badge'       => $menu['badge']       ?? null,
                        'feature_key' => $menu['feature_key'] ?? null,
                        'single'      => true,
                    ];
                }

                if (($menu['type'] ?? null) !== 'groups' || ! isset($menu['sub_menus'])) {
                    continue;
                }

                foreach ($menu['sub_menus'] as $sub) {
                    if (! isset($sub['route'])) {
                        continue;
                    }

                    $url = self::url((string) $sub['route'], $sub['params'] ?? []);

                    if ($url === null) {
                        continue;
                    }

                    $entries[] = [
                        'label'       => (string) $sub['label'],
                        'route'       => (string) $sub['route'],
                        'url'         => $url,
                        'icon'        => (string) ($sub['icon'] ?? $menu['icon'] ?? 'setting'),
                        'params'      => $sub['params']     ?? null,
                        'permission'  => $sub['permission'] ?? $sub['can'] ?? null,
                        'section'     => (string) $menu['label'],
                        'parent'      => $sectionLabel,
                        'badge'       => $sub['badge']       ?? null,
                        'feature_key' => $sub['feature_key'] ?? $menu['feature_key'] ?? null,
                        'single'      => false,
                    ];
                }
            }
        }

        return $entries;
    }

    private function allowed(?string $permission): bool
    {
        return $permission === null || in_array($permission, $this->permissions, true);
    }

    private static function enabled(FeatureManager $features, ?string $feature): bool
    {
        if ($feature === null) {
            return true;
        }

        try {
            return $features->isEnabled($feature);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $haystacks
     */
    private static function matches(string $needle, array $haystacks): bool
    {
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sidebar's glyph for this entry, as a URL the palette can mask with.
     *
     * The config names icons in the sidebar's vocabulary and the sidebar maps
     * them to files; the same map is used here so a page wears the same
     * picture in both places. A name with no file falls back to the sidebar's
     * own default rather than to an empty tile.
     */
    private static function iconUrl(string $icon): string
    {
        $map     = config('admin_menus.sidebar_icons.map', []);
        $default = (string) config('admin_menus.sidebar_icons.default', 'sidebar-subitem');
        $name    = (string) ($map[$icon] ?? $icon);

        try {
            if (! Storage::disk('svg_assets')->exists($name.'.svg')) {
                $name = $default;
            }
        } catch (Throwable) {
            $name = $default;
        }

        return asset('general/static/svg/'.$name.'.svg');
    }

    /**
     * @param array{count?: int, text?: string, display: string, tone: string} $badge
     */
    private static function badgeText(array $badge): string
    {
        if (isset($badge['count'])) {
            return __(':count pending', ['count' => $badge['display']]);
        }

        return (string) $badge['display'];
    }

    private static function tone(string $tone): string
    {
        return match ($tone) {
            AdminSidebarIndicatorManager::TONE_DANGER => 'danger',
            AdminSidebarIndicatorManager::TONE_WARN   => 'warn',
            default                                   => 'neutral',
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function url(string $route, array $params): ?string
    {
        if (! RouteFacade::has($route)) {
            return null;
        }

        try {
            return route($route, $params);
        } catch (Throwable) {
            return null;
        }
    }
}
