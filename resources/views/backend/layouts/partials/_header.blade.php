@php
    use App\Support\AdminSearch\GlobalSearch;
    use App\Support\AdminShell;

    /*
     * The header.
     *
     * Search is a command palette — _palette.blade.php, driven by
     * admin-palette.js — and notifications and the language list are the
     * existing partial and endpoints; only their surroundings changed. The
     * dropdowns are CoreUI's, so outside-click, Escape and arrow-key
     * navigation come from the framework already loaded rather than from new
     * JavaScript.
     *
     * The bar carries the search and four controls, and nothing else: New,
     * the bell, the language and the profile. Anything new goes into the
     * profile menu's Workspace group, or the Create menu if it creates
     * something — never into the bar.
     */
    $authUser  = auth('admin')->user();
    $authName  = $authUser->name ?? __('Administrator');
    $authEmail = $authUser->email ?? null;
    $authRole  = $authUser?->getRoleNames()->first() ?? __('Administrator');
    $twoFactor = (bool) ($authUser->two_factor_enabled ?? false);

    /*
     * Initials stand in when no picture was uploaded. avatar_alt answers with
     * the stock silhouette in that case, which told an operator nothing about
     * whose session this is; two letters do.
     */
    $hasAvatar    = filled($authUser?->avatar);
    $authInitials = AdminShell::initials($authName) ?: mb_strtoupper(mb_substr($authName, 0, 1));

    $createMenu  = AdminShell::actions('create');
    $profileMenu = AdminShell::actions('profile');
    $languages   = $languages ?? collect();

    /* The scope chips this admin gets: "All" and whatever they may search. */
    $searchScopes = (new GlobalSearch(session('admin_permissions', [])))->availableScopes();
    $wordmark     = AdminShell::wordmark(setting('site_title'));
@endphp

<header class="dk-top">
    {{--
        One control, two jobs: it collapses the column on a desktop and opens
        the drawer on a phone. admin-shell.js decides which from the viewport,
        so the button never has to. Above 992px the column has its own edge
        toggle and this one stands down.
    --}}
    <button class="dk-top__icon-btn dk-top__hamburger" type="button"
            id="admin-header-sidebar-toggle"
            aria-controls="admin-shell-sidebar"
            aria-label="{{ __('Toggle sidebar') }}"
            title="{{ __('Toggle sidebar') }}">
        <x-icon name="cil-menu" width="18" height="18"/>
    </button>

    {{--
        Phones only. The drawer is shut, so nothing on screen says whose panel
        this is; the same two-tone wordmark the sidebar sets does.
    --}}
    <a class="dk-top__wordmark" href="{{ route('admin.dashboard') }}">{{ $wordmark['head'] }}@if($wordmark['accent'] !== '')<span class="dk-top__wordmark-accent">{{ $wordmark['accent'] }}</span>@endif</a>

    {{--
        The palette's opener on a phone, where the trigger below has no room.
        Kept under the id the header has always used for it.

        No d-inline-flex here. The utility carries !important, so it beat the
        inline-grid .dk-top__icon-btn lays down - and place-items only centres
        a grid child. Under flex the glyph fell to the start of the box and
        sat a pixel from the left edge of a 40px button, beside a hamburger
        centred properly. Which viewport shows the button is admin-shell.css's
        business, at the same 575.98px the palette goes full-width at.
    --}}
    <button class="dk-top__icon-btn dk-top__search-btn"
            type="button"
            id="admin-header-search-toggle"
            aria-controls="admin-header-search-panel"
            aria-expanded="false"
            aria-haspopup="dialog"
            aria-label="{{ __('Search') }}"
            title="{{ __('Search') }}"
            data-dk-palette-open>
        <x-icon name="search" width="18" height="18"/>
    </button>

    {{--
        The trigger is a button, not an input: the palette owns focus, and a
        field here would be a second place to type that searched nothing. The
        copy the script needs travels on the wrapper so the script ships no
        English of its own.
    --}}
    <div class="dk-top__search"
         data-dk-palette
         data-search-url="{{ route('admin.app.global-search') }}"
         data-recent-key="dk.admin.palette.recent.{{ (int) ($authUser->id ?? 0) }}"
         data-label-recent="{{ __('Recent') }}"
         data-label-start="{{ __('Start typing to search.') }}"
         data-label-no-match="{{ __('No matches for “:query” in :scope') }}"
         data-label-no-match-hint="{{ __('Try another scope, or a different spelling.') }}"
         data-label-unavailable="{{ __('Search is unavailable right now.') }}">
        <button class="dk-top__search-trigger" type="button"
                id="admin-header-search-trigger"
                aria-haspopup="dialog"
                aria-expanded="false"
                aria-controls="admin-header-search-panel"
                data-dk-palette-open>
            <span class="dk-top__search-trigger-icon" aria-hidden="true">
                <x-icon name="search" width="16" height="16"/>
            </span>
            <span class="dk-top__search-trigger-text">{{ __('Search pages, users, transactions…') }}</span>
            {{-- A shortcut reads in the order the keys are pressed, in every language. --}}
            <span class="dk-top__kbd-pair" dir="ltr" aria-hidden="true"><kbd class="dk-top__kbd">Ctrl</kbd><kbd class="dk-top__kbd">K</kbd></span>
        </button>

        @include('backend.layouts.partials._palette', ['scopes' => $searchScopes])
    </div>

    <div class="dk-top__end">
        @if($createMenu !== [])
            <div class="dropdown dk-top__new-wrap" data-dk-create>
                <button class="dk-top__new" type="button"
                        data-coreui-toggle="dropdown"
                        data-coreui-offset="0,10"
                        aria-expanded="false">
                    <x-icon name="plus" width="12" height="12"/>
                    <span class="dk-top__new-label">{{ __('New') }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end dk-top__menu" role="menu">
                    <li class="dk-top__menu-head" role="presentation">
                        <span class="dk-top__menu-heading">
                            <span class="dk-top__menu-heading-title">{{ __('Create') }}</span>
                            <span class="dk-top__menu-heading-sub">{{ __('Quick actions you can take') }}</span>
                        </span>
                        {{-- Bound: admin-palette.js opens this menu on N while focus is outside a field. --}}
                        <kbd class="dk-top__menu-head-kbd" dir="ltr">N</kbd>
                    </li>
                    <li><hr class="dk-top__menu-rule dk-top__menu-rule--head"></li>
                    @include('backend.layouts.partials._header_menu', ['items' => $createMenu])
                </ul>
            </div>

            {{--
                Inside the guard, because the rule exists to separate the New
                button from the rest. An administrator holding none of the
                create permissions was getting the divider on its own — a
                hairline with nothing to its left.
            --}}
            <span class="dk-top__rule" aria-hidden="true"></span>
        @endif

        <div class="dropdown dk-top__notify" id="append-new-admin-notification">
            @include('backend.layouts.partials._notifications', ['notifications' => $authUser->recentNotificationFeed()])
        </div>
        {{--
            The unread count, read out when it changes. It lives outside the
            container the poller repaints, because a live region that is itself
            replaced announces nothing.
        --}}
        <span class="visually-hidden" aria-live="polite" data-dk-notify-live></span>

        @if($languages->isNotEmpty())
            @php($active = $languages->firstWhere('code', app()->getLocale()) ?? $languages->first())
            <div class="dropdown">
                <button class="dk-top__lang" type="button"
                        data-coreui-toggle="dropdown"
                        data-coreui-offset="0,10"
                        aria-expanded="false"
                        aria-label="{{ __('Language') }}">
                    <img src="{{ asset($active->flag) }}" width="18" height="13" alt="" loading="lazy">
                    <span class="dk-top__lang-code" dir="ltr">{{ strtoupper($active->code) }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end dk-top__menu dk-top__menu--narrow" role="menu">
                    <li class="dk-top__menu-head" role="presentation">
                        <span class="dk-top__menu-heading">
                            <span class="dk-top__menu-heading-title">{{ __('Language') }}</span>
                            <span class="dk-top__menu-heading-sub">{{ __('Applies to this admin session') }}</span>
                        </span>
                        <span class="dk-top__menu-tally">{{ $languages->count() }}</span>
                    </li>
                    <li><hr class="dk-top__menu-rule dk-top__menu-rule--head"></li>

                    @foreach($languages as $language)
                        @php($isActive = $language->code === app()->getLocale())
                        @php($direction = $language->is_rtl ? __('RTL') : __('LTR'))
                        <li>
                            <a @class(['dropdown-item', 'dk-top__menu-item', 'is-active' => $isActive])
                               href="{{ route('locale-set', $language->code) }}" role="menuitem">
                                {{--
                                    A flag file that never arrived leaves an
                                    empty 30×22 hole in an otherwise even
                                    column, so the code fills the tile instead.
                                --}}
                                <span @class(['dk-top__menu-flag', 'is-empty' => blank($language->flag)]) aria-hidden="true">
                                    @if(filled($language->flag))
                                        <img src="{{ asset($language->flag) }}" alt="" loading="lazy">
                                    @else
                                        {{ strtoupper($language->code) }}
                                    @endif
                                </span>
                                <span class="dk-top__menu-text">
                                    {{--
                                        The language's own name on top: this
                                        list is read by people who cannot read
                                        the panel around it, and that is the
                                        one word they will recognise. The name
                                        in the current language goes below it,
                                        for anyone who knows it by that.
                                    --}}
                                    <span class="dk-top__menu-label">{{ $language->displayName() }}</span>
                                    <span class="dk-top__menu-hint">{{ $language->name }} · {{ $direction }}</span>
                                </span>
                                <span class="dk-top__menu-code" dir="ltr">{{ strtoupper($language->code) }}</span>
                                @if($isActive)
                                    <span class="dk-top__menu-check" aria-hidden="true">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 12 10 17 19 7"/></svg>
                                    </span>
                                @else
                                    <svg class="dk-top__menu-go" width="12" height="12" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                                @endif
                            </a>
                        </li>
                    @endforeach

                    @if(\Illuminate\Support\Facades\Route::has('admin.language.index') && in_array('language-list', session('admin_permissions', []), true))
                        <li><hr class="dk-top__menu-rule"></li>
                        <li>
                            <a class="dropdown-item dk-top__menu-item dk-top__menu-item--quiet"
                               href="{{ route('admin.language.index') }}" role="menuitem">
                                <span class="dk-top__menu-glyph" aria-hidden="true">
                                    <x-icon name="cil-settings" width="14" height="14"/>
                                </span>
                                <span class="dk-top__menu-text">
                                    <span class="dk-top__menu-label">{{ __('Manage languages') }}</span>
                                </span>
                                <svg class="dk-top__menu-go" width="12" height="12" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="dropdown">
            <button class="dk-top__profile" type="button"
                    data-coreui-toggle="dropdown"
                    data-coreui-offset="0,10"
                    aria-expanded="false"
                    aria-label="{{ $authName }} {{ __('account menu') }}">
                <span class="dk-top__avatar">
                    @if($hasAvatar)
                        <img src="{{ asset($authUser->avatar_alt) }}" alt="" loading="lazy">
                    @else
                        <span class="dk-top__avatar-initials" aria-hidden="true">{{ $authInitials }}</span>
                    @endif
                    <span class="dk-top__presence" aria-hidden="true"></span>
                </span>
                <span class="dk-top__who">
                    <span class="dk-top__who-name">{{ $authName }}</span>
                    <span class="dk-top__who-role">{{ $authRole }}</span>
                </span>
                {{-- The affordance the trigger lacked: nothing said it opened. --}}
                <svg class="dk-top__chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <ul class="dropdown-menu dropdown-menu-end dk-top__menu dk-top__menu--profile" role="menu">
                <li class="dk-top__card">
                    <span class="dk-top__card-figure">
                        @if($hasAvatar)
                            <img class="dk-top__card-avatar" src="{{ asset($authUser->avatar_alt) }}" alt="" loading="lazy">
                        @else
                            <span class="dk-top__card-avatar dk-top__card-initials" aria-hidden="true">{{ $authInitials }}</span>
                        @endif
                        <span class="dk-top__card-presence" aria-hidden="true"></span>
                    </span>
                    <span class="dk-top__card-body">
                        <span class="dk-top__card-name">{{ $authName }}</span>
                        @if($authEmail)
                            <span class="dk-top__card-email">{{ $authEmail }}</span>
                        @endif
                        <span class="dk-top__card-pills">
                            <span class="dk-top__card-role">{{ $authRole }}</span>
                            @if($twoFactor)
                                <span class="dk-top__card-2fa">
                                    <x-icon name="cil-lock-locked" width="9" height="9"/>
                                    {{ __('2FA on') }}
                                </span>
                            @endif
                        </span>
                    </span>
                </li>

                {{--
                    Two groups, because the list mixes "this is my account" with
                    "this is the installation" and a flat run of seven rows made
                    an operator read all seven to find either.

                    Split here rather than in the config, which this change does
                    not own — and split on the URL rather than the label, since
                    the label has already been through __().
                --}}
                @php($accountUrls = collect(['admin.profile.view', 'admin.activity-log'])
                    ->filter(fn (string $name): bool => \Illuminate\Support\Facades\Route::has($name))
                    ->map(fn (string $name): string => route($name))
                    ->all())
                @php($rows = collect($profileMenu)->reject(fn (array $item): bool => $item['divider'] ?? false))
                @php($account = $rows->filter(fn (array $item): bool => in_array($item['url'], $accountUrls, true))->values()->all())
                @php($workspace = $rows->reject(fn (array $item): bool => in_array($item['url'], $accountUrls, true))->values()->all())

                @if($account !== [])
                    <li class="dk-top__menu-eyebrow" role="presentation">{{ __('Account') }}</li>
                    @include('backend.layouts.partials._header_menu', ['items' => $account])
                @endif

                @if($workspace !== [])
                    <li class="dk-top__menu-eyebrow" role="presentation">{{ __('Workspace') }}</li>
                    @include('backend.layouts.partials._header_menu', ['items' => $workspace])
                @endif

                <li><hr class="dk-top__menu-rule"></li>
                <li>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item dk-top__menu-item is-danger" role="menuitem">
                            <span class="dk-top__menu-tile" aria-hidden="true">
                                <x-icon name="cil-account-logout" width="16" height="16"/>
                            </span>
                            <span class="dk-top__menu-text">
                                <span class="dk-top__menu-label">{{ __('Sign out') }}</span>
                                <span class="dk-top__menu-hint">{{ __('Ends this admin session') }}</span>
                            </span>
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
