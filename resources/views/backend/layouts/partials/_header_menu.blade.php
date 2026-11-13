@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    /**
     * The rows inside a header dropdown.
     *
     * $items comes from AdminShell::actions(), already filtered to routes that
     * exist and permissions this admin holds, so nothing here needs to guard.
     *
     * `dropdown-item` is not decoration. CoreUI's dropdown finds the rows to
     * move focus between with `.dropdown-item:not(.disabled):not(:disabled)`,
     * so without it Arrow Up and Arrow Down did nothing and the menu could
     * only be worked with a pointer. admin-shell.css loads after CoreUI's, so
     * our own rules still decide how the row looks.
     *
     * The second line, the shortcut hint and the deposit count are copy the
     * menu config does not carry, and config/admin_menus.php is not this
     * change's to edit. They are keyed by URL rather than by label: the label
     * arriving here has already been through __(), so matching English
     * strings would find nothing the moment the panel is switched to Arabic
     * and every row would silently lose its second line.
     */
    $dkUrlFor = static function (string $name): ?string {
        if (! RouteFacade::has($name)) {
            return null;
        }

        try {
            return route($name);
        } catch (\Throwable) {
            return null;
        }
    };

    $dkHints = [];

    foreach ([
        'admin.user.index'                 => 'Opens the user directory',
        'admin.deposit.manual-request'     => 'Review queue',
        'admin.currency.create'            => 'Currency management',
        'admin.notifications.notifyToUser' => 'Notify users',
        'admin.staff.index'                => 'Team members',
        'admin.profile.view'               => 'Password, avatar & 2FA',
        'admin.activity-log'               => 'Your recent admin actions',
        'admin.app.control-panel'          => 'Site & system status',
        'home'                             => 'Opens in a new tab',
        'admin.app.clear-cache'            => 'Maintenance · safe to run',
        'admin.lock'                       => 'Locks this session',
    ] as $dkRoute => $dkHint) {
        if (($dkUrl = $dkUrlFor($dkRoute)) !== null) {
            $dkHints[$dkUrl] = __($dkHint);
        }
    }

    $dkBadgeUrl = $dkUrlFor('admin.deposit.manual-request');

    /*
     * The same map the sidebar draws its badges from, already in view scope.
     * No second query: if the composer has not supplied it, the row simply
     * carries no count.
     */
    $dkDeposits = (int) ($adminMenuBadges['deposits_manual']['count'] ?? 0);

    /*
     * Rows past the divider are a different kind of thing — "Invite staff"
     * next to four create actions — so they drop the brand tile for a neutral
     * one rather than reading as a fifth thing to create.
     */
    $dkPastDivider = false;
@endphp

@foreach($items as $item)
    @if($item['divider'] ?? false)
        @php
            $dkPastDivider = true;
        @endphp
        <li><hr class="dk-top__menu-rule"></li>
    @else
        @php
            $dkHint     = $dkHints[$item['url']] ?? $item['note'] ?? null;
            $dkExternal = (bool) ($item['external'] ?? false);
            $dkCount    = ($dkBadgeUrl !== null && $item['url'] === $dkBadgeUrl) ? $dkDeposits : 0;
        @endphp
        <li>
            <a @class(['dropdown-item', 'dk-top__menu-item', 'is-neutral' => $dkPastDivider, 'is-danger' => $item['danger'] ?? false])
               href="{{ $item['url'] }}"
               role="menuitem"
               @if($dkExternal) target="_blank" rel="noopener" @endif>
                @if(($item['icon'] ?? '') !== '')
                    <span class="dk-top__menu-tile" aria-hidden="true">
                        <x-icon name="{{ $item['icon'] }}" width="16" height="16"/>
                    </span>
                @endif
                <span class="dk-top__menu-text">
                    <span class="dk-top__menu-label">{{ $item['label'] }}</span>
                    @if($dkHint)
                        <span class="dk-top__menu-hint">{{ $dkHint }}</span>
                    @endif
                </span>
                @if($dkCount > 0)
                    <span class="dk-top__menu-count">{{ $dkCount > 99 ? '99+' : $dkCount }}</span>
                @endif
                {{--
                    The lock row used to end in a "⌘ L" hint that nothing
                    bound - and nothing can: browsers keep Ctrl/Cmd+L for the
                    address bar. A shortcut the row does not honour is a
                    promise, so the row ends in a chevron like the rest.
                --}}
                @if($dkExternal)
                    <svg class="dk-top__menu-go dk-top__menu-go--out" width="12" height="12" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                @else
                    <svg class="dk-top__menu-go" width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                @endif
            </a>
        </li>
    @endif
@endforeach
