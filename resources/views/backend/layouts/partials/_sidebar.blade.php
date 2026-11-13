@php
    use App\Services\FeatureManager;
    use App\Support\AdminShell;
    use App\Support\AdminSidebarIndicatorManager;
    use Illuminate\Support\Facades\Route as RouteFacade;

    /*
     * The navigation column.
     *
     * Order, grouping, gating and badges all come from config/admin_menus.php;
     * nothing about the tree is decided here. The collapsed state arrives from
     * a cookie so the first paint is already right rather than flashing open
     * and snapping shut.
     */
    $shellState  = $adminShellState ?? ['collapsed' => false, 'groups' => []];
    $badges      = $adminMenuBadges ?? [];
    $permissions = session('admin_permissions', []);
    $features    = app(FeatureManager::class);

    $iconMap     = config('admin_menus.sidebar_icons.map', []);
    $iconDefault = config('admin_menus.sidebar_icons.default', 'sidebar-subitem');

    $icon    = static fn (?string $name): string => blank($name) ? $iconDefault : ($iconMap[$name] ?? $iconDefault);
    $allowed = static fn (?string $permission): bool => $permission === null || in_array($permission, $permissions, true);
    $enabled = static fn (?string $feature): bool => $feature === null || $features->isEnabled($feature);

    $titled = static function (?array $badge): ?array {
        if ($badge === null) {
            return null;
        }

        return $badge + [
            'title' => isset($badge['count'])
                ? __('Pending items: :count', ['count' => $badge['count']])
                : (string) $badge['display'],
        ];
    };

    $badgeFor = static fn (?string $key): ?array => $titled($key === null ? null : ($badges[$key] ?? null));

    /*
     * Is this entry the page we are on?
     *
     * isActive() matches route names exactly, so an entry knew about its own
     * index and nothing else — and 119 of the panel's 204 pages highlighted
     * nothing at all. Every detail, edit and create screen lost the pill, and
     * the group above it lost the pill with it.
     *
     * `active_routes` is the entry's own answer to that: the route names and
     * Str::is() patterns that count as it being current. Absent, an entry
     * still falls back to its own route, so nothing in a customised config
     * has to change to keep working.
     */
    $isCurrent = static fn (array $entry): bool => trim(isActive(
        $entry['active_routes'] ?? $entry['route'] ?? [],
        $entry['params'] ?? [],
    )) !== '';

    $sections = collect(config('admin_menus', []))
        ->filter(fn ($section): bool => is_array($section) && isset($section['menus']))
        ->sortBy(fn (array $section): int => (int) ($section['order'] ?? 100));

    /*
     * A group is current when one of its children is — or when it owns the
     * page itself. A ticket detail screen belongs to Support tickets without
     * belonging to any one of its four queues, and saying otherwise would have
     * four rows claiming the same page.
     */
    $groupIsCurrent = static function (array $menu) use ($allowed, $enabled, $isCurrent): bool {
        $subs = collect($menu['sub_menus'] ?? [])->filter(
            fn (array $sub): bool => $allowed($sub['permission'] ?? $sub['can'] ?? null)
                && $enabled($sub['feature_key'] ?? null)
        );

        return $subs->contains(fn (array $sub): bool => $isCurrent($sub))
            || (isset($menu['active_routes']) && $isCurrent($menu));
    };

    /*
     * At most one group is open, and the page decides which — nothing else.
     *
     * The group holding the current page is open; on a page belonging to no
     * group, such as the dashboard or the transactions list, every group is
     * shut. Carrying the operator's last-opened group across in the cookie is
     * what this replaces, and it had to go: whether it survived depended on
     * the order the cookie happened to be written in, so the same click shut a
     * six-row group on one visit and left it open on the next. Measured, that
     * shutting moved 103 of the column's 106 rows, one of them by 182px.
     *
     * Deriving it from the route alone makes the answer the same every time,
     * which is what stops the column appearing to move: the first paint is
     * already final, and admin-shell.js reads the rendered column into its
     * state rather than applying anything over the top of it.
     */
    $openGroup = null;

    foreach ($sections as $section) {
        foreach ($section['menus'] ?? [] as $menu) {
            if (($menu['type'] ?? '') !== 'single' && $groupIsCurrent($menu)) {
                $openGroup = (string) str($menu['code'] ?? $menu['label'])->slug('-');

                break 2;
            }
        }
    }

    /*
     * The wordmark, split so it can be set in two tones like the logotype
     * beside it. An accent of '' renders an empty span and looks exactly as
     * the flat title does today, which is what a one-word site title gets.
     */
    $wordmark = AdminShell::wordmark(setting('site_title'));

    /*
     * A mark drawn for a dark sidebar is usually flat white, and this one is.
     * There is no colour in the file to show, so it takes the accent the
     * wordmark uses — but only when the file is genuinely single-colour, so
     * a buyer who uploaded a coloured logo keeps every one of its colours.
     */
    $markPath   = setting('small_logo');
    $markTinted = AdminShell::markIsMonochrome($markPath);

    /*
     * The column's health card is gone. It restated what Platform health on
     * the dashboard says in full, on every screen, in a strip too narrow to
     * say it properly — and it was the tallest thing between the nav and the
     * bottom of the column. The foot is one System row: the build, the
     * environment and the door to System information.
     */
@endphp

<aside @class(['dk-side', 'is-collapsed' => $shellState['collapsed']])
       id="admin-shell-sidebar"
       data-dk-side
       data-state-key="{{ AdminShell::STATE_STORAGE_KEY }}"
       data-state-cookie="{{ AdminShell::STATE_COOKIE }}"
       data-scroll-key="{{ AdminShell::SCROLL_STORAGE_KEY }}"
       aria-label="{{ __('Admin navigation') }}">

    {{--
        The collapse control sits on the sidebar/header seam rather than inside
        the header, so it stays attached to the thing it collapses.
    --}}
    <button class="dk-side__edge" type="button"
            data-dk-side-toggle
            aria-controls="admin-shell-sidebar"
            aria-expanded="{{ $shellState['collapsed'] ? 'false' : 'true' }}"
            aria-label="{{ __('Collapse sidebar') }}"
            title="{{ __('Collapse sidebar') }}">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 6 9 12 15 18"></polyline></svg>
    </button>

    <div class="dk-side__brand">
        <a class="dk-side__mark-link" href="{{ route('admin.dashboard') }}">
            <span @class(['dk-side__mark', 'is-tinted' => $markTinted])
                  @if($markTinted) style="--dk-mark: url('{{ asset($markPath) }}')" @endif>
                <img src="{{ asset($markPath) }}" width="24" height="24" alt="" loading="lazy">
            </span>
            {{--
                Two tones, like the logotype beside it.

                The brand's own mark sets a dark "Digi" against an indigo
                "Kash"; the wordmark here was flat white and read as a
                different product from the logo it sits next to.
                AdminShell::wordmark() finds the split rather than storing
                it, and returns an empty accent for a title that has none —
                so a one-word title still renders exactly as before.
            --}}
            <span class="dk-side__wordmark">
                <span class="dk-side__name">{{ $wordmark['head'] }}<span class="dk-side__name-accent">{{ $wordmark['accent'] }}</span></span>
                <span class="dk-side__kicker">{{ __('Admin console') }}</span>
            </span>
        </a>

        {{-- Mobile only: the drawer's own way out. --}}
        <button class="dk-side__close" type="button" data-dk-side-close aria-label="{{ __('Close') }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"></path></svg>
        </button>
    </div>

    <nav class="dk-side__nav">
        @foreach($sections as $section)
            @php
                $menus = collect($section['menus'] ?? [])->filter(function (array $menu) use ($allowed, $enabled): bool {
                    if (! $enabled($menu['feature_key'] ?? null) || ! $allowed($menu['permission'] ?? null)) {
                        return false;
                    }

                    if (($menu['type'] ?? '') !== 'groups') {
                        return true;
                    }

                    /* A group with nothing left inside it is not a group. */
                    return collect($menu['sub_menus'] ?? [])->contains(
                        fn (array $sub): bool => $allowed($sub['permission'] ?? $sub['can'] ?? null)
                            && $enabled($sub['feature_key'] ?? null)
                    );
                });
            @endphp

            @continue($menus->isEmpty())

            @if(! empty($section['label']))
                <div class="dk-side__section">{{ __($section['label']) }}</div>
            @endif

            @foreach($menus as $menu)
                @if(($menu['type'] ?? '') === 'single')
                    @include('backend.layouts.partials._sidebar_link', ['row' => [
                        'label'  => __($menu['label']),
                        'href'   => route($menu['route'], $menu['params'] ?? []),
                        'icon'   => $icon($menu['icon'] ?? null),
                        'active' => $isCurrent($menu),
                        'badge'  => $badgeFor($menu['badge'] ?? null),
                        'level'  => 'item',
                    ]])
                @else
                    @php
                        $subs = collect($menu['sub_menus'] ?? [])->filter(
                            fn (array $sub): bool => $allowed($sub['permission'] ?? $sub['can'] ?? null)
                                && $enabled($sub['feature_key'] ?? null)
                        )->values();

                        $groupKey = (string) str($menu['code'] ?? $menu['label'])->slug('-');

                        $isActive = $groupIsCurrent($menu);

                        /* One at a time; $openGroup above settled which. */
                        $isOpen = $groupKey === $openGroup;

                        $groupBadge = $badgeFor($menu['badge'] ?? null)
                            ?? $titled(AdminSidebarIndicatorManager::rollUp(
                                $badges,
                                array_values(array_filter($subs->pluck('badge')->all()))
                            ));
                    @endphp

                    <div @class(['dk-side__group', 'is-open' => $isOpen]) data-dk-side-group-wrap="{{ $groupKey }}">
                        @include('backend.layouts.partials._sidebar_link', ['row' => [
                            'label'  => __($menu['label']),
                            'href'   => '#',
                            'icon'   => $icon($menu['icon'] ?? null),
                            'active' => $isActive,
                            'open'   => $isOpen,
                            'group'  => $groupKey,
                            'badge'  => $groupBadge,
                            'level'  => 'item',
                        ]])

                        {{--
                            A shut group is hidden, not merely small.

                            The children stay in the DOM so the collapse can
                            animate, which means a keyboard would otherwise
                            tab straight through eight invisible links on the
                            way to the next section, and a screen reader would
                            read them out.
                        --}}
                        <div class="dk-side__subs" @if(! $isOpen) aria-hidden="true" @endif>
                            {{-- One child, so the 0fr/1fr collapse has a single row to close. --}}
                            <div class="dk-side__subs-inner">
                            @foreach($subs as $sub)
                                @include('backend.layouts.partials._sidebar_link', ['row' => [
                                    'label'    => __($sub['label']),
                                    'href'     => route($sub['route'], $sub['params'] ?? []),
                                    'icon'     => $icon($sub['icon'] ?? null),
                                    'active'   => $isCurrent($sub),
                                    'badge'    => $badgeFor($sub['badge'] ?? null),
                                    'level'    => 'sub',
                                    'reachable' => $isOpen,
                                ]])
                            @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        @endforeach
    </nav>

    {{--
        Which installation you are signed in to, which build of it, and the
        door to the page that says the rest.

        Drawn as one more nav row so it collapses, hovers and focuses exactly
        like the rows above it. The dot on the glyph is the part that matters:
        amber the moment this is not production, so nobody approves a
        withdrawal on staging believing they are live. It sits on the glyph
        rather than beside the text so the 72px rail keeps it.

        The badge is the update count, in the badge the rest of the column
        uses, and only for someone who can act on it. Without app-info the row
        still states the build but goes nowhere — an <a> with no href, which
        is neither focusable nor clickable.

        Never is-active: System information under Platform › Settings owns
        that page, and two rows claiming one page is the defect the
        active_routes work removed.
    --}}
    <div class="dk-side__foot">
        @php
            $environment = AdminShell::environment();
            $updates     = AdminShell::updatesAvailable();
            $canOpen     = RouteFacade::has('admin.app.info') && $allowed('app-info');
            $canUpdate   = $updates > 0 && RouteFacade::has('admin.app.updater.index') && $allowed('project-updater-view');
            $updateText  = trans_choice(':count update available|:count updates available', $updates, ['count' => $updates]);
            $footTitle   = AdminShell::version().' · '.$environment['label'].($canUpdate ? ' · '.$updateText : '');
        @endphp

        <a @class(['dk-side__row', 'dk-side__row--item', 'dk-side__system', 'is-inert' => ! $canOpen])
           @if($canOpen) href="{{ route('admin.app.info') }}" @endif
           title="{{ $footTitle }}">

            <span class="dk-side__icon dk-side__system-icon" aria-hidden="true">
                <x-icon name="sidebar-server" width="20" height="20"/>
                <span @class(['dk-side__system-dot', 'is-staging' => ! $environment['live']])></span>
            </span>

            <span class="dk-side__label dk-side__system-label">
                <span class="dk-side__system-name">{{ __('System') }}</span>
                <span class="dk-side__system-meta" dir="ltr"><span class="dk-side__system-ver">{{ AdminShell::version() }}</span> · {{ $environment['label'] }}</span>
            </span>

            @if($canUpdate)
                <span class="dk-side__badge dk-side__badge--warn dk-side__system-badge" dir="ltr" title="{{ $updateText }}">
                    <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5m-7 7 7-7 7 7"/></svg>{{ $updates }}
                </span>
                <span class="dk-side__pip dk-side__pip--warn" aria-hidden="true"></span>
            @endif

            @if($canOpen)
                <span class="dk-side__system-go" aria-hidden="true">
                    <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
                </span>
            @endif
        </a>
    </div>
    {{--
        Where the column was scrolled to, put back before it is painted.

        The navigation is 1522px of rows in a 745px window, so an operator
        working in the lower half has scrolled it. Clicking a link there used
        to land on a page whose column started at the top again — measured at
        400px of movement — so the row they had just clicked was no longer
        where they left it. That is the menu appearing to jump.

        It is inline, and here rather than in admin-shell.js, because the fix
        has to be true before the first frame. That script is a separate file
        at the end of the body: locally it happens to arrive 136ms ahead of
        first paint, but that is a race, and on a buyer's server over a real
        connection it is one the paint wins — and then the correction is
        something the operator watches happen.

        Last in the column, and that part is load-bearing.

        It used to sit directly under </nav>, where the foot below it had not
        been parsed yet. The column is a full-height flex stack and the nav is
        its flex:1 child, so at that moment the nav still measured the foot's
        own height as part of itself — and this reads getBoundingClientRect()
        off it. The branch that scrolls an active row into view was therefore
        aiming at a viewport taller than the one that would exist a moment
        later, landing the row under the health card, and the forced layout
        came due while the column was still half-built. Run last, it measures
        the column that will actually be on screen.
    --}}
    <script>
        (function () {
            var nav = document.querySelector('.dk-side__nav');

            if (!nav) {
                return;
            }

            var stored = null;

            try {
                stored = window.sessionStorage.getItem('{{ AdminShell::SCROLL_STORAGE_KEY }}');
            } catch (error) {
                /* Private windows and blocked storage simply start at the top. */
            }

            if (stored !== null) {
                nav.scrollTop = parseInt(stored, 10) || 0;
                nav.dataset.scrollRestored = '1';

                return;
            }

            /* Nothing remembered yet: show the row for the page being opened. */
            var active = nav.querySelector('.dk-side__row.is-active');

            if (!active) {
                return;
            }

            var view = nav.getBoundingClientRect();
            var row = active.getBoundingClientRect();

            if (row.top < view.top) {
                nav.scrollTop += row.top - view.top;
            } else if (row.bottom > view.bottom) {
                nav.scrollTop += row.bottom - view.bottom;
            }
        })();
    </script>
</aside>

<div class="dk-side__scrim" data-dk-side-scrim hidden></div>
