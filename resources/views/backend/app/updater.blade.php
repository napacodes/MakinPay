@extends('backend.layouts.app')

@section('title', __('Project updater'))

@push('styles')
    @php
        $puCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/project-updater.css'));
    @endphp
    <link rel="stylesheet" href="{{ asset('backend/css/project-updater.css?v='.$puCssVersion) }}">
@endpush

@section('content')
    @php
        /*
         * The page is one state machine.
         *
         * Every block names the states it belongs to in `data-show` and the
         * stylesheet hides the rest, so a state change is a single attribute
         * on the root. That is what lets the install run replace the whole
         * page with the pipeline without a second route or a second render.
         */
        $currentVersion = (string) config('app.version');
        $licenseActive  = $license?->isActive() ?? false;

        /*
         * The recovery archive holds a database dump and the storage folder,
         * so on the public demo install — where the admin credentials are
         * published — it is refused by DemoMode and by the service itself.
         * The button stays visible and explains why rather than disappearing:
         * an evaluator is meant to see that the updater takes backups.
         */
        $isDemo             = (bool) config('app.demo', false);
        $backupDownloadable = $licenseActive && ! $isDemo;
        $backupDemoNote     = __('Downloading a recovery backup is disabled in demo mode.');

        /*
         * Defaulted the conservative way round. A caller that forgets to pass
         * it gets "the check may be out of date", never a freshness claim the
         * page cannot support.
         */
        $checkIsStale = $checkIsStale ?? true;
        $justInstalled  = session('updater_just_installed');
        $installFailed  = $latest && $latest->status === 'failed';

        /*
         * A stored `available` is not a fact about now.
         *
         * The status is frozen at check time, so this installation had a
         * v2.0.1 row still saying "available" while running 2.6.4 — and the
         * page duly offered to install it. Every question below is asked of
         * the version, which stays true without being re-checked; `status`
         * only says which *kind* of row this is.
         *
         * `$updateAvailable` is a state the page can be in. `$hasUpdate` is
         * "there is a release outstanding", which stays true after a failed
         * install — that is exactly what the retry would install.
         */
        $releaseIsNewer = $latest?->isNewerThanInstalled() ?? false;

        /*
         * "Is a release outstanding" is the version question, and nothing
         * else. Gating it on `status` as well left a third hole: roll back
         * 2.6.4 and the row keeps status `rolled_back`, so the page went back
         * to announcing "Up to date" while sitting on 2.6.3 with 2.6.4 plainly
         * ahead of it — the same false reassurance as the stale check, arrived
         * at from the other direction.
         */
        $hasUpdate = $releaseIsNewer;

        /* The state machine still cares which kind of row it is. */
        $updateAvailable = $latest && $latest->status === 'available' && $releaseIsNewer;

        /* Facts the release server sent, read through the shapes it uses. */
        $releaseFacts = $latest?->releaseFacts() ?? [
            'bytes' => null, 'files' => null, 'migrations' => null, 'filename' => null,
            'repo' => null, 'ref' => null, 'commit' => null, 'forced' => false, 'signed' => false,
        ];

        $checks       = collect($checks ?? []);
        $checksTotal  = $checks->count();
        $checksPassed = $checks->where('status', true)->count();
        $checksFailed = $checksTotal - $checksPassed;
        $checksReady  = $checksTotal > 0 && $checksFailed === 0;

        $initialState = match (true) {
            (bool) $justInstalled => 'install-success',
            $installFailed        => 'install-failed',
            ! $license            => 'idle-nolicense',
            $updateAvailable      => 'update-available',
            default               => 'license-active',
        };

        /* Every state except the install run, which takes the page over. */
        $liveStates = 'idle-nolicense license-active update-available install-success install-failed';

        /*
         * An update installs from `available` and retries from `failed` — and
         * in both cases only when it is actually ahead of the running code.
         * The service refuses the same install independently.
         */
        $installable = $latest
            && in_array($latest->status, ['available', 'failed', 'rolled_back'], true)
            && ($releaseIsNewer || ($installFailed && $latest->version === $currentVersion));

        $supportDaysRemaining = $license?->supportDaysRemaining();
        $supportExpired       = $license && $supportDaysRemaining !== null && $supportDaysRemaining < 0;
        $supportEndingSoon    = $license?->supportExpiresSoon() ?? false;

        $fmtBytes = function (?float $bytes): string {
            $bytes = (float) $bytes;

            return match (true) {
                $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 1).' GB',
                $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1).' MB',
                $bytes > 0          => number_format($bytes / 1024, 1).' KB',
                default             => '—',
            };
        };

        $backupAgeDays = $backup
            ? (int) $backup['taken_at']->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : null;
    @endphp

    <div class="project-updater" data-state="{{ $initialState }}">

        {{--
            The header, and every block below it, is hidden for the duration of
            an install: the run owns the page while it is happening, and a
            "Check for updates" button beside a live pipeline invites the one
            click that must not happen.
        --}}
        <div data-show="{{ $liveStates }}">
            @include('backend.app.partials._updater_header')
        </div>

        {{-- ── The update just landed ─────────────────────────────────── --}}
        <div class="pu-banner pu-banner--success" data-show="install-success">
            <div class="pu-banner__icon"><i class="fas fa-check" aria-hidden="true"></i></div>
            <div>
                <div class="pu-banner__title">{{ __('Update installed successfully') }}</div>
                <div class="pu-banner__sub">
                    {{ __('This installation is now running') }}
                    <span class="pu-mono">v{{ $justInstalled ?: $latest?->version }}</span>.
                    {{ __('The recovery snapshot taken before the install is still on disk.') }}
                </div>
            </div>
        </div>

        {{--
            The Envato support-window alert was removed in Milestone 1 along
            with core licensing: support expiry no longer gates anything, and
            project updates carry no support-window condition.
        --}}

        {{-- ── Main column + rail ─────────────────────────────────────── --}}
        <div class="pu-cols" data-show="{{ $liveStates }}">
            <main class="pu-main">
                @include('backend.app.partials._updater_failure')
                @include('backend.app.partials._updater_release')
                @include('backend.app.partials._updater_readiness')
                @include('backend.app.partials._updater_history')
            </main>

            <aside class="pu-rail">
                @include('backend.app.partials._updater_rail')
            </aside>
        </div>

        {{-- ── The install run ────────────────────────────────────────── --}}
        <div data-show="install-progress">
            @include('backend.app.partials._updater_running')
        </div>
    </div>

    @include('backend.app.partials._updater_install_modal')
    @include('backend.app.partials._updater_rollback_modal')

    {{-- Server troubleshooting modal — always rendered, opened via JS. --}}
    @include('backend.app.partials._updater_troubleshooting')
@endsection

@push('scripts')
    <script>
        window.projectUpdaterConfig = {
            currentVersion: @json($currentVersion),
            latestVersion: @json($latest?->version),
            channel: @json($latest?->channel ?? config('project_updater.channel')),
            i18n: {
                verifying: @json(__('Verifying…')),
                checking: @json(__('Checking…')),
                preparing: @json(__('Preparing…')),
                refreshing: @json(__('Refreshing…')),
                generateBackup: @json(__('Generate recovery backup')),
                autoScrollOn: @json(__('Auto-scroll on')),
                autoScrollOff: @json(__('Auto-scroll off')),
                licenseHintChars: @json(__('characters')),
                rollbackTo: @json(__('Restores the files this install replaced, back to :version')),
                rollbackFiles: @json(__('Restores the files this install replaced')),
                rollingBack: @json(__('Rolling back…')),
                copied: @json(__('Copied'))
            }
        };
    </script>
    @php
        $puJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/project-updater.js'));
    @endphp
    <script src="{{ asset('backend/js/project-updater.js?v='.$puJsVersion) }}"></script>
@endpush
