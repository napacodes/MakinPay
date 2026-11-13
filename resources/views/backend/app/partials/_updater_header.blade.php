@php
    /*
     * One header, one status pill, one primary action — and the primary is
     * whatever this state actually wants next: activate, install, retry, or
     * take a snapshot. The version badge is the only place the running build
     * is printed, so nothing below it repeats the number.
     */
    $headerStatus = match (true) {
        $initialState === 'idle-nolicense'   => ['tone' => 'warning', 'label' => __('License required')],
        $initialState === 'update-available' => ['tone' => 'brand',   'label' => __('Update available')],
        $initialState === 'install-failed'   => ['tone' => 'danger',  'label' => __('Last install failed')],
        $initialState === 'install-success'  => ['tone' => 'success', 'label' => __('Update installed')],
        /*
         * Asked of the version, not of the state name. A release backed out
         * of is still a release ahead of you, and the state for that row is
         * `license-active` — which fell through to "Up to date".
         */
        $hasUpdate                           => ['tone' => 'brand',   'label' => __('Update available')],
        /*
         * "Up to date" is only ever as true as the last check. This
         * installation was showing it from a check three months old, next to
         * an offer to install a release older than the running code.
         */
        $checkIsStale                        => ['tone' => 'warning', 'label' => __('Update check is out of date')],
        default                              => ['tone' => 'success', 'label' => __('Up to date')],
    };

    $lastChecked = $latest?->checked_at;

    $headerStats = [
        /*
         * Without a licence there is no answer to "how far behind am I" — the
         * update check is what produces it. A green "0 · latest" in that state
         * is a reassurance the page has not earned, so it prints an em dash.
         */
        [
            'label' => __('Releases behind'),
            'value' => match (true) {
                ! $licenseActive => '—',
                $checkIsStale    => '?',
                default          => $hasUpdate ? 1 : 0,
            },
            'delta' => match (true) {
                ! $licenseActive => __('unknown'),
                $hasUpdate       => 'v'.$latest->version,
                $checkIsStale    => __('stale'),
                default          => __('latest'),
            },
            'tone'  => match (true) {
                ! $licenseActive => 'neutral',
                $hasUpdate       => 'warning',
                $checkIsStale    => 'warning',
                default          => 'success',
            },
            'note'  => match (true) {
                ! $licenseActive => __('activate the license to check'),
                $installFailed   => __('install failed — retry'),
                $hasUpdate       => __('ready to install'),
                $checkIsStale    => $lastChecked
                    ? __('last checked :when', ['when' => $lastChecked->diffForHumans()])
                    : __('never checked'),
                default          => __('checked :when', ['when' => $lastChecked->diffForHumans()]),
            },
            'icon'  => 'app',
        ],
        [
            'label' => __('License'),
            'value' => $licenseActive ? __('Active') : __('Missing'),
            'delta' => match (true) {
                ! $licenseActive => __('required'),
                $supportExpired  => __('Support ended'),
                default          => __('Extended'),
            },
            'tone'  => match (true) {
                ! $licenseActive => 'danger',
                $supportExpired  => 'warning',
                default          => 'success',
            },
            'note'  => match (true) {
                ! $licenseActive               => __('activate to enable updates'),
                $license?->support_until !== null => __('to :date', ['date' => $license->support_until->translatedFormat('d M Y')]),
                default                        => __('updates are free for life'),
            },
            'icon'  => 'kyc',
        ],
        [
            'label' => __('Last backup'),
            'value' => $backup
                ? ($backupAgeDays < 1 ? __('Today') : trans_choice('{1} :count d|[2,*] :count d', $backupAgeDays, ['count' => $backupAgeDays]))
                : __('None'),
            'delta' => $backup ? $fmtBytes($backup['bytes']) : __('recommended'),
            'tone'  => $backup ? 'brand' : 'warning',
            'note'  => $backup
                ? __('verified · :date', ['date' => $backup['taken_at']->translatedFormat('d M')])
                : __('take one before installing'),
            'icon'  => 'layer',
        ],
        /*
         * The strip's fourth cell. The reference carries 30-day uptime here;
         * nothing in this application records uptime, so the slot goes to the
         * other number an operator wants before pressing install — whether
         * this server can actually run one.
         */
        [
            'label' => __('Environment'),
            'value' => $checksTotal > 0 ? $checksPassed.' / '.$checksTotal : '—',
            'delta' => $checksReady ? __('ready') : trans_choice('{1} :count failing|[2,*] :count failing', $checksFailed, ['count' => $checksFailed]),
            'tone'  => $checksReady ? 'success' : 'danger',
            'note'  => __('checked on this request'),
            'icon'  => 'check',
        ],
    ];

    /*
     * A failing environment check is the difference between an install and a
     * half-written application directory, so the button says so rather than
     * letting the operator discover it three phases in. The server refuses the
     * same install independently — this is the courtesy, not the guard.
     */
    $installBlockedBy = $checksReady
        ? null
        : ($checks->firstWhere('status', false)['label'] ?? __('an environment check'));
@endphp

<x-page-header
    :eyebrow="__('App management')"
    :title="__('Project updater')"
    icon="app"
    tile="soft"
    :badge="'v'.$currentVersion"
    :subtitle="__('Keep this installation on the latest verified release. A full database and storage snapshot is taken before every install.')"
    :status="$headerStatus"
    :stats="$headerStats"
    {{--
        The menu-derived trail reads "Platform › Settings › Project updater",
        because the sidebar files this page under Settings. The page belongs to
        App management and the header says so.
    --}}
    :breadcrumbs="[['label' => __('Platform')], ['label' => __('App management')], ['label' => __('Project updater')]]"
>
    <x-slot:actions>
        @can('project-updater-view')
            <form action="{{ route('admin.app.updater.check') }}" method="POST">
                @csrf
                <button type="submit" class="ph-btn ph-btn--secondary" @disabled(! $licenseActive)
                    @if(! $licenseActive) title="{{ __('Activate the license to check for updates.') }}" @endif>
                    <x-icon name="apps-1" width="16" height="16"/>{{ __('Check for updates') }}
                </button>
            </form>
        @endcan

        @can('project-updater-manage')
            @if($initialState === 'idle-nolicense')
                <button type="button" class="ph-btn ph-btn--primary" data-action="focus-license">
                    <x-icon name="kyc" width="16" height="16"/>{{ __('Activate license') }}
                </button>
            @elseif($installable)
                <button type="button" class="ph-btn ph-btn--primary" data-action="open-install-modal"
                    @disabled(! $checksReady)
                    @if($installBlockedBy) title="{{ __('Blocked by :check — fix it under Environment readiness.', ['check' => $installBlockedBy]) }}" @endif>
                    <x-icon name="check" width="16" height="16"/>
                    {{ $installFailed ? __('Retry install') : __('Install v:version', ['version' => $latest->version]) }}
                </button>
            @else
                <form action="{{ route('admin.app.updater.backup.download') }}" method="POST">
                    @csrf
                    <button type="submit" class="ph-btn ph-btn--primary" @disabled(! $backupDownloadable)
                        @if($isDemo) title="{{ $backupDemoNote }}" @endif>
                        <x-icon name="layer" width="16" height="16"/>{{ __('Create backup') }}
                    </button>
                </form>
            @endif
        @endcan
    </x-slot:actions>

    <x-slot:menu>
        <button type="button" class="dropdown-item" data-action="open-help-modal"
            @if($failureCategory) data-open-category="{{ $failureCategory }}" @endif>
            <span class="ph-menu__tile"><x-icon name="support"/></span>
            <span class="ph-menu__copy">
                <span class="ph-menu__label">{{ __('Troubleshooting') }}</span>
                <span class="ph-menu__hint">{{ __('Common install failures and their fixes') }}</span>
            </span>
        </button>

        @can('site-setting-view')
            <div class="dropdown-divider"></div>

            <a href="{{ route('admin.settings.site.index') }}" class="dropdown-item is-warning">
                <span class="ph-menu__tile"><x-icon name="lock"/></span>
                <span class="ph-menu__copy">
                    <span class="ph-menu__label">{{ __('Maintenance mode') }}</span>
                    <span class="ph-menu__hint">{{ __('Take the site offline from Site settings') }}</span>
                </span>
            </a>
        @endcan
    </x-slot:menu>
</x-page-header>
