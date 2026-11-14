@php
    /*
     * The rail carries the three things that outlive any single release: who
     * this copy is licensed to, what there is to fall back on, and where to
     * read when an install goes wrong.
     *
     * The purchase code is printed masked. It is the one secret on this page
     * and an operator only ever needs to recognise it, never to read it back.
     */
    $purchaseCode = $license?->safePurchaseCode();
    /*
     * Four characters each end, and a fixed run of dots between them.
     *
     * The mask kept the original 8-4-4-4-12 grouping at first, which is 36
     * monospaced characters — wider than the rail, so it wrapped onto a second
     * line inside the row. The operator only ever needs to recognise the code,
     * and the two ends do that; a fixed dot run also stops the mask leaking
     * how long the stored value was.
     */
    $maskedPurchaseCode = filled($purchaseCode) && mb_strlen((string) $purchaseCode) > 12
        ? mb_substr((string) $purchaseCode, 0, 4).str_repeat('•', 16).mb_substr((string) $purchaseCode, -4)
        : null;

    $hasUsableCode = $license?->hasUsablePurchaseCode() ?? false;

    $helpLinks = [
        ['category' => 'permissions', 'label' => __('An install failed on file permissions')],
        ['category' => 'database',    'label' => __('A migration failed part-way through')],
        ['category' => 'timeout',     'label' => __('The install timed out or ran out of memory')],
    ];
@endphp

{{-- ── Update channel ─────────────────────────────────────────────────── --}}
{{--
    The core Envato license card lived here. Licensing was removed in
    Milestone 1, so this card states how updates are obtained and what
    protects them. It deliberately shows no purchase code: none is required,
    and an installation that still carries one keeps it in the database
    untouched rather than displaying it here.
--}}
<section class="pu-card" data-show="{{ $liveStates }}">
    <header class="pu-card__head">
        <div class="pu-card__head-main">
            <div class="pu-card__head-text">
                <h2 class="pu-card__title">{{ __('Updates') }}</h2>
            </div>
        </div>
        <span class="pu-card__head-meta pu-mono" dir="ltr">{{ request()->getHost() }}</span>
    </header>

    <div class="pu-facts">
        <div class="pu-fact">
            <span class="pu-fact__k">{{ __('Release channel') }}</span>
            <span class="pu-fact__v">{{ ucfirst((string) config('project_updater.channel', 'stable')) }}</span>
        </div>
        <div class="pu-fact">
            <span class="pu-fact__k">{{ __('Installed version') }}</span>
            <span class="pu-fact__v pu-mono" dir="ltr">{{ config('project_updater.version') }}</span>
        </div>
        <div class="pu-fact">
            <span class="pu-fact__k">{{ __('Package integrity') }}</span>
            <span class="pu-fact__v">{{ __('Signed releases, verified on install') }}</span>
        </div>
    </div>

    <p class="pu-card__hint">
        {{ __('Update packages are verified against MakinPay’s signing key before anything is written. A package that fails verification is rejected.') }}
    </p>
</section>

{{-- ── Recovery backup ────────────────────────────────────────────────── --}}
@can('project-updater-manage')
    <section class="pu-card" data-show="{{ $liveStates }}">
        <header class="pu-card__head">
            <div class="pu-card__head-main">
                <div class="pu-card__head-text">
                    <h2 class="pu-card__title">{{ __('Recovery backup') }}</h2>
                </div>
            </div>
            <span class="pu-card__head-meta">{{ $backup ? __('Latest') : __('None yet') }}</span>
        </header>

        <div class="pu-card__body">
            <div class="pu-snapshot">
                <span class="pu-snapshot__icon pu-snapshot__icon--{{ $backup ? 'ok' : 'idle' }}" aria-hidden="true">
                    <x-icon name="layer" width="18" height="18"/>
                </span>
                <span class="pu-snapshot__copy">
                    <span class="pu-snapshot__title">
                        {{ $backup ? $backup['name'] : __('No snapshot on disk yet') }}
                    </span>
                    <span class="pu-snapshot__path pu-mono" dir="ltr">
                        {{ $backup['path'] ?? 'storage/app/'.trim((string) config('project_updater.backups_path'), '/').'/' }}
                    </span>
                </span>
            </div>

            @if($backup)
                <div class="pu-facts">
                    <div class="pu-fact">
                        <span class="pu-fact__k">{{ __('Size') }}</span>
                        <span class="pu-fact__v">{{ $fmtBytes($backup['bytes']) }}</span>
                    </div>
                    <div class="pu-fact">
                        <span class="pu-fact__k">{{ __('Taken') }}</span>
                        <span class="pu-fact__v">
                            {{ $backup['taken_at']->translatedFormat('d M Y · H:i') }}
                        </span>
                    </div>
                </div>
            @endif

            <p class="pu-card__hint">
                {{ __('Includes a database dump and the storage folder. Snapshots are never deleted automatically — remove old ones yourself when disk space is tight.') }}
            </p>

            @if($isDemo)
                <p class="pu-card__hint">
                    {{ $backupDemoNote }}
                    {{ __('The archive would contain this installation\'s database and uploaded files, so it is never built while the panel is public.') }}
                </p>
            @endif
        </div>

        <footer class="pu-card__foot">
            <form action="{{ route('admin.app.updater.backup.download') }}" method="POST" class="pu-card__foot-form">
                @csrf
                <button type="submit" class="pu-btn pu-btn--secondary pu-btn--block" @disabled(! $backupDownloadable)
                    @if($isDemo) title="{{ $backupDemoNote }}" @endif>
                    <i class="fas fa-download" aria-hidden="true"></i> {{ __('Generate recovery backup') }}
                </button>
            </form>
        </footer>
    </section>
@endcan

{{-- ── Need help? ─────────────────────────────────────────────────────── --}}
<section class="pu-card" data-show="{{ $liveStates }}">
    <header class="pu-card__head">
        <div class="pu-card__head-main">
            <div class="pu-card__head-text">
                <h2 class="pu-card__title">{{ __('Need help?') }}</h2>
            </div>
        </div>
    </header>

    <div class="pu-help-links">
        @foreach($helpLinks as $link)
            <button type="button" class="pu-help-link" data-action="open-help-modal" data-open-category="{{ $link['category'] }}">
                <span>{{ $link['label'] }}</span>
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        @endforeach
    </div>
</section>
