<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProjectUpdate;
use App\Services\BackgroundHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Throwable;

/**
 * Everything the admin chrome needs to draw itself.
 *
 * The sidebar and header render on every admin page, so each value here is
 * either a config read or something another service already computed. Nothing
 * in this class queries the database on its own.
 *
 * updatesAvailable() reads the updater's stored check, behind a cache the
 * updater clears, so a page load is one cache hit there too.
 *
 * The one exception is markIsMonochrome(), which reads the brand image — and
 * it is cached on that file's own modification time, so a page load costs one
 * cache hit and an upload is what re-reads it.
 */
final class AdminShell
{
    /**
     * Read by PHP on the first paint and written by JS afterwards, so a
     * collapsed sidebar renders collapsed instead of flashing open and
     * snapping shut. The localStorage twin uses the dotted spelling.
     */
    public const STATE_COOKIE = 'dk_admin_sidebar';

    public const STATE_STORAGE_KEY = 'dk.admin.sidebar';

    /**
     * Where the navigation column was scrolled to.
     *
     * Session storage rather than the cookie beside it: it is per-tab, the
     * server has no use for it, and sending 4 bytes of scroll offset on every
     * request would be paying for it twice.
     */
    public const SCROLL_STORAGE_KEY = 'dk.admin.sidebar.scroll';

    /** Forgotten by the updater whenever it stores a new check. */
    public const UPDATES_CACHE_KEY = 'admin.shell.updates_available';

    public const UPDATES_CACHE_SECONDS = 300;

    /**
     * The sidebar's remembered state, as far as the server can know it.
     *
     * @return array{collapsed: bool, groups: array<string, bool>}
     */
    public static function state(?Request $request = null): array
    {
        $empty = ['collapsed' => false, 'groups' => []];
        $raw   = ($request ?? request())->cookie(self::STATE_COOKIE);

        if (! is_string($raw) || $raw === '') {
            return $empty;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            /* A cookie someone hand-edited is not worth a 500. */
            return $empty;
        }

        if (! is_array($decoded)) {
            return $empty;
        }

        $groups = [];

        /*
         * `groups` has to be checked, not merely defaulted.
         *
         * The value is a scalar in {"groups":"nope"}, and foreach over a
         * string is a TypeError - a 500 on every admin page until the cookie
         * is cleared, which the operator has no way to guess. It was
         * unreachable while the cookie encrypter discarded this cookie
         * wholesale; now that the script's own plaintext copy is read, the
         * contents are whatever a browser chooses to send.
         */
        $rawGroups = $decoded['groups'] ?? [];

        if (! is_array($rawGroups)) {
            $rawGroups = [];
        }

        foreach ($rawGroups as $key => $open) {
            if (is_string($key) && $key !== '') {
                $groups[$key] = (bool) $open;
            }
        }

        return [
            'collapsed' => (bool) ($decoded['collapsed'] ?? false),
            'groups'    => $groups,
        ];
    }

    /**
     * The environment pill.
     *
     * Green is reserved for production. Everywhere else is amber on purpose:
     * the pill exists so nobody approves a withdrawal on staging believing
     * they are live.
     *
     * @return array{label: string, live: bool}
     */
    public static function environment(): array
    {
        $environment = (string) config('app.env', 'production');

        if ($environment === 'production') {
            return ['label' => __('Live'), 'live' => true];
        }

        return [
            'label' => match ($environment) {
                'staging' => __('Staging'),
                'local'   => __('Local'),
                'testing' => __('Testing'),
                default   => ucfirst($environment),
            },
            'live' => false,
        ];
    }

    /**
     * The site title, split so the sidebar can set it in two tones.
     *
     * The brand's own logotype is two-colour — a dark "Digi" against an
     * indigo "Kash" — and the wordmark beside it was flat white, which read
     * as a different product from the logo it sat next to.
     *
     * The split is found, never configured: the last camelCase hump if the
     * title has one, otherwise the last word. A title with neither returns an
     * empty accent and renders exactly as it does now, so an installation
     * whose title is a single lowercase word changes not at all.
     *
     * @return array{head: string, accent: string}
     */
    public static function wordmark(?string $title): array
    {
        $title = trim((string) $title);

        if ($title === '') {
            return ['head' => '', 'accent' => ''];
        }

        /* DigiKash -> Digi | Kash. The last hump, so PassCashPay keeps "Pay". */
        if (preg_match('/^(.*\p{Ll})(\p{Lu}[\p{L}\p{N}]*)$/u', $title, $hump) === 1) {
            return ['head' => $hump[1], 'accent' => $hump[2]];
        }

        /* "Pay Wallet" -> "Pay " | "Wallet". */
        $cut = mb_strrpos($title, ' ');

        if ($cut !== false && $cut > 0) {
            return [
                'head'   => mb_substr($title, 0, $cut + 1),
                'accent' => mb_substr($title, $cut + 1),
            ];
        }

        return ['head' => $title, 'accent' => ''];
    }

    /**
     * Is the brand mark a single-colour glyph?
     *
     * A logo drawn for a dark sidebar is usually flat white, and this one is:
     * every opaque pixel of it is white or the grey of its own antialiasing.
     * There is no "main colour" in the file to show, so the column tints it
     * with the accent the wordmark uses instead — but only when tinting
     * cannot destroy anything, which is what this answers.
     *
     * A buyer who uploads a genuinely coloured logo gets it untouched.
     *
     * Cached on the file's path and modification time, so an upload re-reads
     * and everything else is one cache hit. Any doubt at all — missing file,
     * no GD, an SVG, an unreadable format — is answered "no", which leaves
     * the mark exactly as it renders today.
     */
    public static function markIsMonochrome(?string $path): bool
    {
        if (blank($path)) {
            return false;
        }

        $full = public_path($path);

        if (! is_file($full) || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $key = 'admin.shell.mark-mono.'.md5($full.'|'.filemtime($full));

        return (bool) Cache::remember($key, now()->addDay(), static fn (): bool => self::sampleMonochrome($full));
    }

    /**
     * The share of a mark's opaque pixels that carry any colour at all.
     *
     * Sampled on a grid rather than pixel by pixel: a mark is a few hundred
     * pixels of glyph and the answer does not get truer for reading all of
     * them.
     */
    private static function sampleMonochrome(string $full): bool
    {
        try {
            $raw = @file_get_contents($full);

            if ($raw === false) {
                return false;
            }

            $image = @imagecreatefromstring($raw);

            if ($image === false) {
                return false;
            }

            $width  = imagesx($image);
            $height = imagesy($image);
            $step   = max(1, (int) floor(min($width, $height) / 48));

            $opaque   = 0;
            $coloured = 0;

            for ($y = 0; $y < $height; $y += $step) {
                for ($x = 0; $x < $width; $x += $step) {
                    $rgba = imagecolorat($image, $x, $y);

                    /* GD alpha runs 0 (opaque) to 127 (transparent). */
                    if ((($rgba >> 24) & 0x7F) > 100) {
                        continue;
                    }

                    $opaque++;

                    $red   = ($rgba >> 16) & 0xFF;
                    $green = ($rgba >> 8)  & 0xFF;
                    $blue  = $rgba         & 0xFF;

                    if (max($red, $green, $blue) - min($red, $green, $blue) > 24) {
                        $coloured++;
                    }
                }
            }

            imagedestroy($image);

            /* Nothing opaque is not a mark worth tinting either. */
            return $opaque > 0 && ($coloured / $opaque) < 0.05;
        } catch (Throwable) {
            return false;
        }
    }

    public static function version(): string
    {
        return 'v'.config('app.version');
    }

    /**
     * How many releases are waiting to be installed, for the badge on the
     * sidebar foot.
     *
     * Read from the newest update check the updater already stored — never a
     * call to the update server on a page load. A check always returns the
     * server's current release, so that one row answers the question; whether
     * it is ahead of the running code is the row's own isNewerThanInstalled().
     * Installing it moves app.version, which is why the version is part of the
     * cache key: the badge clears on the first page after an install instead
     * of five minutes later.
     *
     * Zero when nothing is known — a fresh installation, a missing table — so
     * a site that has never checked shows no badge rather than a guess.
     */
    public static function updatesAvailable(): int
    {
        try {
            return (int) Cache::remember(
                self::UPDATES_CACHE_KEY.':'.config('app.version'),
                self::UPDATES_CACHE_SECONDS,
                static function (): int {
                    $latest = ProjectUpdate::query()->latest('checked_at')->latest('id')->first();

                    return $latest !== null && $latest->installed_at === null && $latest->isNewerThanInstalled() ? 1 : 0;
                }
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Up to two initials for an avatar tile that has no picture.
     *
     * The first letter of the first and last words — "Sadia Ahmed" reads SA,
     * "Uddin Traders" UT — so a merchant's business name and a person's name
     * come out the same shape. A single word gives one letter, a blank gives
     * nothing, and the caller decides what to draw then.
     */
    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = mb_substr($words[0], 0, 1);
        $last  = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * The background health card at the foot of the sidebar.
     *
     * Three states, because an operator needs to tell "the cron has stopped"
     * apart from "nothing has reported in yet" — the second is what a fresh
     * installation looks like, and calling that a failure would be a lie.
     *
     * @return array{tone: string, title: string, detail: string}
     */
    public static function health(): array
    {
        try {
            $summary = app(BackgroundHealthService::class)->summary();
        } catch (Throwable) {
            return [
                'tone'   => 'unknown',
                'title'  => __('Background status unavailable'),
                'detail' => __('Open background jobs to check'),
            ];
        }

        $scheduler = $summary['scheduler'];
        $queue     = $summary['queue'];
        $parts     = [];

        if ($scheduler['minutes_ago'] !== null) {
            $parts[] = __('Cron :time', ['time' => self::ago((int) $scheduler['minutes_ago'])]);
        }

        if ($queue['pending'] !== null) {
            $parts[] = __('queue :count', ['count' => (int) $queue['pending']]);
        }

        if ((int) ($queue['failed'] ?? 0) > 0) {
            $parts[] = __(':count failed', ['count' => (int) $queue['failed']]);
        }

        $detail = $parts === [] ? __('No report yet') : implode(' · ', $parts);

        if ($summary['needs_attention']) {
            return [
                'tone'  => 'bad',
                'title' => $scheduler['status'] === BackgroundHealthService::STATUS_STALLED
                    ? __('Scheduler has stalled')
                    : __('Queue is backing up'),
                'detail' => $detail,
            ];
        }

        if ($scheduler['status'] === BackgroundHealthService::STATUS_UNKNOWN) {
            return [
                'tone'   => 'unknown',
                'title'  => __('Waiting for the first run'),
                'detail' => $detail,
            ];
        }

        return [
            'tone'   => 'ok',
            'title'  => __('All systems normal'),
            'detail' => $detail,
        ];
    }

    /**
     * Where the current page sits in the menu.
     *
     * Derived from config/admin_menus.php rather than declared per page: the
     * config already knows every route's parent, and ~80 blades each yielding
     * their own trail would drift apart within a release.
     *
     * @return list<array{label: string, url: string|null}>
     */
    public static function breadcrumb(): array
    {
        $current = RouteFacade::currentRouteName();

        if (! is_string($current) || $current === '') {
            return [];
        }

        foreach (config('admin_menus', []) as $section) {
            if (! is_array($section) || ! isset($section['menus'])) {
                continue;
            }

            foreach ($section['menus'] as $menu) {
                if (($menu['route'] ?? null) === $current) {
                    return self::trail($section, $menu, null);
                }

                foreach ($menu['sub_menus'] ?? [] as $sub) {
                    if (($sub['route'] ?? null) === $current) {
                        return self::trail($section, $menu, $sub);
                    }
                }
            }
        }

        return [];
    }

    /**
     * The header menus, filtered to what this admin can actually reach.
     *
     * An item whose route does not exist is dropped rather than rendered dead,
     * which is what keeps the "New" menu honest on an installation where a
     * module was never installed.
     *
     * @return list<array<string, mixed>>
     */
    public static function actions(string $group): array
    {
        $items = [];

        foreach (config("admin_menus.header_actions.{$group}", []) as $item) {
            if (($item['divider'] ?? false) === true) {
                /* A divider only earns its place between two real items. */
                if ($items !== [] && ! isset($items[array_key_last($items)]['divider'])) {
                    $items[] = ['divider' => true];
                }

                continue;
            }

            $permission = $item['permission'] ?? null;

            if (is_string($permission) && ! in_array($permission, session('admin_permissions', []), true)) {
                continue;
            }

            $url = self::url($item['route'] ?? null, $item['params'] ?? []);

            if ($url === null) {
                continue;
            }

            $items[] = [
                'label'    => __($item['label']),
                'url'      => $url,
                'icon'     => (string) ($item['icon'] ?? ''),
                'note'     => isset($item['note']) ? __($item['note']) : null,
                'danger'   => (bool) ($item['danger'] ?? false),
                'method'   => (string) ($item['method'] ?? 'get'),
                'external' => (bool) ($item['external'] ?? false),
            ];
        }

        /* Never leave a trailing divider once items have been filtered out. */
        while ($items !== [] && isset($items[array_key_last($items)]['divider'])) {
            array_pop($items);
        }

        return $items;
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    private static function trail(array $section, array $menu, ?array $sub): array
    {
        $trail = [];

        if (! empty($section['label'])) {
            /* A section groups; it is not somewhere you can go. */
            $trail[] = ['label' => __($section['label']), 'url' => null];
        }

        $trail[] = [
            'label' => __($menu['label']),
            'url'   => $sub === null ? null : self::url($menu['route'] ?? null, $menu['params'] ?? []),
        ];

        if ($sub !== null) {
            $trail[] = ['label' => __($sub['label']), 'url' => null];
        }

        return $trail;
    }

    private static function url(?string $route, array $params = []): ?string
    {
        if ($route === null || ! RouteFacade::has($route)) {
            return null;
        }

        try {
            return route($route, $params);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ago(int $minutes): string
    {
        if ($minutes < 1) {
            return __('just now');
        }

        if ($minutes < 60) {
            return __(':count min ago', ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return __(':count hr ago', ['count' => $hours]);
        }

        return __(':count d ago', ['count' => intdiv($hours, 24)]);
    }
}
