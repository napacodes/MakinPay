<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Extra ways to deposit, shown beside core's own on the customer's Add Money
 * page. An add-on registers one from its provider; it appears only while its
 * route exists - that is, while the add-on is running - and its visibility
 * check passes for the customer looking at the page.
 */
final class DepositEntryPoints
{
    /** @var array<string, array{label: string, route: string, icon: string, visible: (Closure(User): bool)|null}> */
    private static array $entries = [];

    /**
     * @param string                     $label   untranslated; translated when the page is drawn
     * @param (Closure(User): bool)|null $visible whether this customer may use it
     */
    public static function register(string $key, string $label, string $route, string $icon, ?Closure $visible = null): void
    {
        if (preg_match('/^[a-z0-9_-]{2,40}$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid deposit entry point %s.', $key));
        }

        self::$entries[$key] = ['label' => $label, 'route' => $route, 'icon' => $icon, 'visible' => $visible];
    }

    public static function forget(string $key): void
    {
        unset(self::$entries[$key]);
    }

    /**
     * @return list<array{label: string, url: string, icon: string}>
     */
    public function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $shown = [];

        foreach (self::$entries as $entry) {
            if (! Route::has($entry['route']) || ($entry['visible'] !== null && ! ($entry['visible'])($user))) {
                continue;
            }

            $shown[] = ['label' => __($entry['label']), 'url' => route($entry['route']), 'icon' => $entry['icon']];
        }

        return $shown;
    }
}
