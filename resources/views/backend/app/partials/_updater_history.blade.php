@php
    /*
     * Every release this installation has seen, newest first — and most of
     * them are checks, not installs.
     *
     * Rendering a check as if it were an install is what left this table with
     * four empty columns and three different old versions all labelled
     * "Current": no install date, no duration, no actor, no backup, and no
     * hint that none of those could ever exist for that row. Each row now
     * says which kind it is, and only an install attempt is asked how long it
     * took and who ran it.
     *
     * The backup column asks the disk rather than trusting the stored path:
     * snapshots are pruned by hand and by hosting panels, and a row promising
     * a restore target that is gone is worse than one admitting it expired.
     */
    $installAttempts = $history->filter(fn ($row) => $row->wasInstallAttempt())->count();
@endphp

<section class="pu-card" data-show="{{ $liveStates }}">
    <header class="pu-card__head">
        <div class="pu-card__head-main">
            <div class="pu-card__head-text">
                <h2 class="pu-card__title">{{ __('Update history') }}</h2>
                <p class="pu-card__subtitle">{{ __('Every release this installation has seen — update checks and installs.') }}</p>
            </div>
        </div>
        @if($history->isNotEmpty())
            @php
                /* Built as one string: split across lines the separator lands
                   in its own text node and the line reads "1 install ·" then
                   "2 checks" to anything matching on the rendered HTML. */
                $historyMeta = trans_choice('{0} no installs yet|{1} :count install|[2,*] :count installs', $installAttempts, ['count' => $installAttempts])
                    .' · '
                    .trans_choice('{1} :count check|[2,*] :count checks', $history->count(), ['count' => $history->count()]);
            @endphp
            <span class="pu-card__head-meta">{{ $historyMeta }}</span>
        @endif
    </header>

    @if($history->isNotEmpty())
        <div class="pu-table-wrap">
            <table class="pu-table pu-table--history">
                <thead>
                    <tr>
                        <th>{{ __('Version') }}</th>
                        <th>{{ __('Result') }}</th>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Duration · by') }}</th>
                        <th>{{ __('Backup') }}</th>
                        @can('project-updater-manage')
                            <th><span class="pu-sr-only">{{ __('Roll back') }}</span></th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($history as $row)
                        @php
                            $isRunning    = $row->version === $currentVersion;
                            $wasAttempt   = $row->wasInstallAttempt();
                            $backupOnDisk = $row->backupIsOnDisk();

                            $backupLabel = match (true) {
                                blank($row->backup_path)  => $wasAttempt ? __('None') : '—',
                                ! $backupOnDisk           => __('Expired'),
                                $row->status === 'failed' => __('Preserved'),
                                default                   => __('Available'),
                            };

                            /* An install is dated by its install; a check by its check. */
                            $rowMoment = $row->installed_at ?? $row->checked_at ?? $row->created_at;
                        @endphp

                        <tr>
                            <td>
                                <span class="pu-table__version" dir="ltr">v{{ $row->version }}</span>
                                @if($isRunning)
                                    <span class="pu-table__dot" title="{{ __('Running now') }}" aria-label="{{ __('Running now') }}"></span>
                                @endif
                            </td>
                            <td>
                                <span class="pu-table__result pu-table__result--{{ $row->statusTone() }}">
                                    <span class="pu-pill__dot"></span>{{ $row->resultLabel() }}
                                </span>
                                <span class="pu-table__sub">{{ $row->resultNote() }}</span>
                            </td>
                            <td>
                                <span class="pu-table__time">{{ $rowMoment?->translatedFormat('d M Y') ?? '—' }}</span>
                                <span class="pu-table__sub">
                                    {{ $row->installed_at ? __('installed') : __('checked') }}
                                    {{ $rowMoment?->format('H:i') }}
                                </span>
                            </td>
                            <td>
                                @if($wasAttempt)
                                    <span class="pu-table__dur">{{ $row->durationForHumans() ?? '—' }}</span>
                                    <span class="pu-table__sub">{{ $row->installed_by ?: __('unknown') }}</span>
                                @else
                                    {{-- A check has no duration and no operator; saying "— / unknown" invented two. --}}
                                    <span class="pu-table__dur is-muted">{{ __('not installed') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="pu-table__backup {{ $backupOnDisk ? '' : 'is-muted' }}">{{ $backupLabel }}</span>
                                <span class="pu-table__sub pu-mono" dir="ltr">{{ $row->backup_path ? basename($row->backup_path) : '—' }}</span>
                            </td>

                            @can('project-updater-manage')
                                {{--
                                    Disabled rather than hidden, with the reason on the
                                    button: "why can I not roll this one back" is the
                                    question an operator asks at exactly the moment they
                                    most need an answer.
                                --}}
                                <td class="pu-table__action">
                                    <button type="button"
                                        class="pu-btn pu-btn--ghost pu-btn--sm"
                                        data-action="open-rollback-modal"
                                        data-version="{{ $row->version }}"
                                        data-version-from="{{ $row->version_from }}"
                                        data-action-url="{{ route('admin.app.updater.rollback', $row) }}"
                                        @disabled(! $row->canRollBack())
                                        @if($rollbackBlocked = $row->rollbackBlockedReason()) title="{{ $rollbackBlocked }}" @endif>
                                        {{ __('Roll back') }}
                                    </button>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="pu-card__body">
            <x-admin-not-found
                :title="__('No update activity')"
                :message="__('Activate the license and run your first update check.')"
                icon="fa-history"
            />
        </div>
    @endif
</section>
