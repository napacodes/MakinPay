<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Extra items in the customer's sidebar, below core's own tools. An add-on
 * registers one from its provider; it appears only while its route exists -
 * that is, while the add-on is running - and its visibility check passes for
 * the customer looking at the page.
 *
 * The visibility check is the add-on's to make with core's own rules (the
 * feature catalog, the merchant gate), exactly as the sidebar does for core
 * items; the route behind it enforces the same rules again.
 */
final class UserMenuEntryPoints
{
    /** @var array<string, array{label: string, route: string, icon: string, active: string, visible: (Closure(User): bool)|null}> */
    private static array $entries = [];

    /**
     * @param string                     $label   untranslated; translated when the menu is drawn
     * @param string                     $icon    an <x-icon> name from the svg set
     * @param string|null                $active  the route-name pattern that marks it active; its own route when null
     * @param (Closure(User): bool)|null $visible whether this customer sees it
     */
    public static function register(string $key, string $label, string $route, string $icon, ?Closure $visible = null, ?string $active = null): void
    {
        if (preg_match('/^[a-z0-9_-]{2,40}$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid menu entry point %s.', $key));
        }

        self::$entries[$key] = ['label' => $label, 'route' => $route, 'icon' => $icon, 'active' => $active ?? $route, 'visible' => $visible];
    }

    public static function forget(string $key): void
    {
        unset(self::$entries[$key]);
    }

    /**
     * @return list<array{key: string, label: string, url: string, icon: string, active: bool}>
     */
    public function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $shown = [];

        foreach (self::$entries as $key => $entry) {
            if (! Route::has($entry['route']) || ($entry['visible'] !== null && ! ($entry['visible'])($user))) {
                continue;
            }

            $shown[] = [
                'key'    => $key,
                'label'  => __($entry['label']),
                'url'    => route($entry['route']),
                'icon'   => $entry['icon'],
                'active' => request()->routeIs($entry['active']),
            ];
        }

        return $shown;
    }
}
