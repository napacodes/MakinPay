@php
    /*
     * The release card answers four questions in one strip — what is out,
     * how big it is, what it costs in downtime, and when it shipped — and
     * then, only when there is something to install, what changed.
     *
     * Every number is read from the manifest the update server sent. Nothing
     * here is estimated on the page's own authority: a missing key prints an
     * em dash, because a made-up package size is worse than no package size
     * when an operator is deciding whether to run this now or at 3 a.m.
     */
    $lastChecked = $latest?->checked_at;

    $releaseSubline = match (true) {
        $hasUpdate       => __('Verified package from the Digikash release channel.'),
        ! $licenseActive => __('Update checks are disabled until a license is activated.'),
        $checkIsStale    => __('The last update check is out of date — run one to see where this install stands.'),
        default          => __('Nothing to install right now.'),
    };

    $releaseChannel = $latest?->channel ?? config('project_updater.channel');

    $packageFiles   = $releaseFacts['files'];
    $migrationCount = $releaseFacts['migrations'];

    $packageNote = match (true) {
        ! $hasUpdate                                      => __('nothing to download'),
        $packageFiles !== null && $migrationCount !== null => __(':files files · :migrations migrations', ['files' => number_format($packageFiles), 'migrations' => $migrationCount]),
        $packageFiles !== null                            => __(':files files', ['files' => number_format($packageFiles)]),
        $releaseFacts['filename'] !== null                => $releaseFacts['filename'],
        default                                           => __('checksum verified before install'),
    };

    /*
     * "Signed" and "checksum verified" are two different promises, and real
     * releases currently ship no signature — printing "signed · sha256
     * verified" for them claimed a check that never ran.
     */
    $verificationNote = $releaseFacts['signed']
        ? __('signed · sha256 verified')
        : __('sha256 verified');

    /*
     * Grouped exactly as release notes are written, so "Fixed" reads as one
     * block instead of three lines scattered through a flat list. An
     * unrecognised tag lands in Improved rather than being dropped — a
     * release note nobody sees is the same as a release note nobody wrote.
     */
    $changelogGroups = collect($latest?->changelog ?? [])
        ->map(function ($entry): array {
            if (is_array($entry)) {
                return [
                    'tag'  => mb_strtolower((string) ($entry['tag'] ?? 'changed')),
                    'text' => trim((string) ($entry['text'] ?? $entry['title'] ?? '')),
                ];
            }

            return ['tag' => 'changed', 'text' => trim((string) $entry)];
        })
        ->filter(fn (array $entry): bool => $entry['text'] !== '')
        ->groupBy(fn (array $entry): string => match (true) {
            in_array($entry['tag'], ['new', 'added', 'add', 'feature'], true) => 'new',
            in_array($entry['tag'], ['fixed', 'fix', 'bugfix'], true)         => 'fixed',
            $entry['tag'] === 'security'                                      => 'security',
            default                                                           => 'improved',
        });

    /*
     * Whether the body below the strip has anything in it at all.
     *
     * Broadening "is a release outstanding" to the version comparison meant a
     * row with no changelog, no checksum and no provenance — a bare `current`
     * row from an old check — opened the body anyway and drew an empty 36px
     * padded band under the strip.
     */
    $hasReleaseDetail = $hasUpdate && (
        $changelogGroups->isNotEmpty()
        || $releaseFacts['forced']
        || ($migrationCount ?? 0) > 0
        || filled($latest?->checksum)
        || $releaseFacts['repo']
        || $releaseFacts['commit']
    );

    $changelogMeta = [
        'new'      => ['label' => __('New'),      'tone' => 'primary'],
        'improved' => ['label' => __('Improved'), 'tone' => 'success'],
        'fixed'    => ['label' => __('Fixed'),    'tone' => 'warning'],
        'security' => ['label' => __('Security'), 'tone' => 'danger'],
    ];
@endphp

<section class="pu-card" data-show="{{ $liveStates }}">
    <header class="pu-card__head">
        <div class="pu-card__head-main">
            <div class="pu-card__head-text">
                <h2 class="pu-card__title">{{ __('Release') }}</h2>
                <p class="pu-card__subtitle">{{ $releaseSubline }}</p>
            </div>
        </div>
        {{--
            The release line, as the reference has it. Stale checks are the
            page's quietest lie — a three-month-old result rendered in the same
            grey as a five-minute-old one — so past the window the same line
            turns amber rather than becoming a pill that competes with the
            header's own status.
        --}}
        <span @class(['pu-card__head-meta', 'is-warning' => $checkIsStale && $licenseActive])>
            @if($hasUpdate && $latest?->release_date)
                {{ __('Released :date · :channel channel', [
                    'date'    => $latest->release_date->translatedFormat('d M Y'),
                    'channel' => $releaseChannel,
                ]) }}
            @else
                {{ $lastChecked ? __('Last checked :when', ['when' => $lastChecked->diffForHumans()]) : __('Never checked') }}
            @endif
        </span>
    </header>

    <div class="pu-strip pu-strip--bordered">
        <div class="pu-strip__cell {{ $hasUpdate ? 'is-brand' : '' }}">
            <span class="pu-strip__k">{{ __('Latest release') }}</span>
            <span class="pu-strip__v pu-mono" dir="ltr">
                @if($hasUpdate)
                    v{{ $latest->version }}
                @elseif($licenseActive)
                    v{{ $currentVersion }}
                @else
                    —
                @endif
            </span>
            <span class="pu-strip__note">
                @if($hasUpdate)
                    {{ $verificationNote }}
                @elseif($licenseActive)
                    {{ __('matches your install') }}
                @else
                    {{ __('activate the license to check') }}
                @endif
            </span>
        </div>

        <div class="pu-strip__cell">
            <span class="pu-strip__k">{{ __('Package') }}</span>
            <span class="pu-strip__v">{{ $hasUpdate ? $fmtBytes($releaseFacts['bytes']) : '—' }}</span>
            <span class="pu-strip__note">{{ $packageNote }}</span>
        </div>

        <div class="pu-strip__cell">
            <span class="pu-strip__k">{{ __('Downtime') }}</span>
            <span class="pu-strip__v">{{ $hasUpdate ? __('During install') : __('None') }}</span>
            <span class="pu-strip__note">
                {{ $hasUpdate ? __('maintenance mode while files copy') : __('no install pending') }}
            </span>
        </div>

        {{--
            The release date moved up to the card's meta line, where it sits
            beside the channel it belongs to. This cell used to repeat it and
            printed an em dash whenever nothing was pending — the reference
            gives the slot to the next auto-check, and the honest equivalent
            here, with no scheduler, is when the last one actually ran.
        --}}
        <div class="pu-strip__cell">
            <span class="pu-strip__k">{{ __('Last checked') }}</span>
            <span class="pu-strip__v">
                {{ $lastChecked ? $lastChecked->translatedFormat('d M Y') : __('Never') }}
            </span>
            <span class="pu-strip__note">
                {{ $lastChecked ? __('checks are manual') : __('run one to find out') }}
            </span>
        </div>
    </div>

    @if($hasReleaseDetail)
        <div class="pu-card__body">
            @if($changelogGroups->isNotEmpty())
                <div class="pu-section-head">
                    <span class="pu-section-head__title">{{ __("What's new in v:version", ['version' => $latest->version]) }}</span>
                </div>

                <div class="pu-clog">
                    @foreach($changelogMeta as $key => $meta)
                        @continue(! $changelogGroups->has($key))

                        <div class="pu-clog__group">
                            <span class="pu-clog__tag pu-clog__tag--{{ $meta['tone'] }}">{{ $meta['label'] }}</span>
                            @foreach($changelogGroups[$key]->take(6) as $entry)
                                <span class="pu-clog__item">{{ $entry['text'] }}</span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif

            @if($releaseFacts['forced'])
                <div class="pu-note pu-note--danger">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <span>
                        <strong>{{ __('The publisher marked this release as required.') }}</strong>
                        {{ __('It carries a fix that should not be deferred — install it at the next quiet moment.') }}
                    </span>
                </div>
            @endif

            @if($migrationCount > 0)
                <div class="pu-note pu-note--warning">
                    <i class="fas fa-database" aria-hidden="true"></i>
                    <span>
                        <strong>{{ trans_choice('{1} Contains :count database migration.|[2,*] Contains :count database migrations.', $migrationCount, ['count' => $migrationCount]) }}</strong>
                        {{ __('A full database and storage snapshot is taken first, so there is something to restore from if anything goes wrong.') }}
                    </span>
                </div>
            @endif

            @if($latest->checksum)
                <div class="pu-checksum">
                    <span class="pu-checksum__k">SHA-256</span>
                    <span class="pu-copy">
                        <span class="pu-copy__val pu-mono" dir="ltr">{{ $latest->checksum }}</span>
                        <button type="button" class="pu-copy__btn" aria-label="{{ __('Copy') }}">
                            <i class="fas fa-copy" aria-hidden="true"></i>
                        </button>
                    </span>
                </div>
            @endif

            {{--
                Where the package came from. Support asks this first when an
                install misbehaves, and the answer was sitting unread in the
                stored payload.
            --}}
            @if($releaseFacts['repo'] || $releaseFacts['commit'])
                <p class="pu-provenance">
                    {{ __('Built from') }}
                    <span class="pu-mono" dir="ltr">{{ $releaseFacts['repo'] ?: __('the release repository') }}</span>
                    @if($releaseFacts['ref'])
                        <span class="pu-mono" dir="ltr">{{ $releaseFacts['ref'] }}</span>
                    @endif
                    @if($releaseFacts['commit'])
                        <span class="pu-mono" dir="ltr">{{ substr($releaseFacts['commit'], 0, 7) }}</span>
                    @endif
                </p>
            @endif
        </div>
    @elseif(! $license)
        <div class="pu-card__body">
            <div class="pu-note">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <span>
                    <strong>{{ __('Activate your Digikash license to enable updates') }}</strong>
                    {{ __('Digikash needs a verified Envato purchase code before it can fetch releases for this domain.') }}
                </span>
            </div>
        </div>
    @endif
</section>
