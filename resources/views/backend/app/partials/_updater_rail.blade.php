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

    $hasUsableCode   = $license?->hasUsablePurchaseCode() ?? false;
    $envatoDownloads = 'https://codecanyon.net/downloads';

    $helpLinks = [
        ['category' => 'permissions', 'label' => __('An install failed on file permissions')],
        ['category' => 'database',    'label' => __('A migration failed part-way through')],
        ['category' => 'timeout',     'label' => __('The install timed out or ran out of memory')],
    ];
@endphp

{{-- ── License ────────────────────────────────────────────────────────── --}}
<section class="pu-card" data-show="{{ $liveStates }}">
    <header class="pu-card__head">
        <div class="pu-card__head-main">
            <div class="pu-card__head-text">
                <h2 class="pu-card__title">{{ __('License') }}</h2>
            </div>
        </div>
        <span class="pu-card__head-meta pu-mono" dir="ltr">{{ $license?->domain ?: request()->getHost() }}</span>
    </header>

    @if($license)
        <div class="pu-facts">
            <div class="pu-fact">
                <span class="pu-fact__k">{{ __('Purchase code') }}</span>
                <span class="pu-fact__v pu-mono" dir="ltr">
                    {{ $maskedPurchaseCode ?? __('Stored · not readable') }}
                </span>
            </div>
            <div class="pu-fact">
                <span class="pu-fact__k">{{ __('Buyer') }}</span>
                <span class="pu-fact__v">{{ $license->buyer_username ?: __('Unknown') }}</span>
            </div>
            <div class="pu-fact">
                <span class="pu-fact__k">{{ __('Support Until') }}</span>
                <span class="pu-fact__v">
                    {{ $license->support_until?->translatedFormat('d M Y') ?? __('Unknown') }}
                </span>
            </div>
            <div class="pu-fact">
                <span class="pu-fact__k">{{ __('Update access') }}</span>
                <span class="pu-fact__v">{{ __('Lifetime Free') }}</span>
            </div>
        </div>

        @can('project-updater-manage')
            <footer class="pu-card__foot pu-card__foot--split">
                @if($hasUsableCode)
                    <form id="pu-refresh-license-form" action="{{ route('admin.app.updater.refresh-license') }}" method="POST">
                        @csrf
                        <button type="submit" class="pu-btn pu-btn--secondary pu-btn--sm pu-btn--block">
                            <i class="fas fa-arrows-rotate" aria-hidden="true"></i> {{ __('Re-verify') }}
                        </button>
                    </form>
                @else
                    <button type="button" class="pu-btn pu-btn--primary pu-btn--sm pu-btn--block" data-action="toggle-reactivate">
                        <i class="fas fa-key" aria-hidden="true"></i> {{ __('Re-activate') }}
                    </button>
                @endif

                <a href="{{ $envatoDownloads }}" target="_blank" rel="noopener noreferrer"
                    class="pu-btn pu-btn--secondary pu-btn--sm pu-btn--block">
                    <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> {{ __('Manage on Envato') }}
                </a>
            </footer>

            @if($hasUsableCode)
                <div class="pu-card__foot pu-card__foot--quiet">
                    <button type="button" class="pu-link" data-action="toggle-reactivate">
                        {{ __('Re-enter purchase code') }}
                    </button>
                </div>
            @else
                <p class="pu-card__hint">
                    {{ __('Stored purchase code cannot be decrypted (APP_KEY may have changed). Re-enter your Envato purchase code to re-bind this installation.') }}
                </p>
            @endif

            <div class="pu-reactivate" id="pu-reactivate-panel" hidden>
                <form action="{{ route('admin.app.updater.activate') }}" method="POST" class="pu-form" id="pu-reactivate-form">
                    @csrf
                    <label class="pu-form__label" for="pu-reactivate-input">{{ __('Envato Purchase Code') }}</label>
                    <input id="pu-reactivate-input"
                        name="purchase_code"
                        type="text"
                        class="pu-input pu-mono"
                        dir="ltr"
                        placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX"
                        maxlength="40"
                        autocomplete="off">
                    <div id="pu-reactivate-hint" class="pu-form__hint">
                        {{ __('Re-entering your purchase code updates this installation’s license token.') }}
                    </div>
                    <div class="pu-form__row">
                        <button type="submit" id="pu-reactivate-submit" class="pu-btn pu-btn--primary pu-btn--sm" disabled>
                            <i class="fas fa-key" aria-hidden="true"></i> {{ __('Re-activate license') }}
                        </button>
                        <button type="button" class="pu-btn pu-btn--ghost pu-btn--sm" data-action="toggle-reactivate">
                            {{ __('Cancel') }}
                        </button>
                    </div>
                </form>
            </div>
        @endcan
    @else
        <div class="pu-card__body">
            <p class="pu-card__lead">
                {{ __('Enter your Envato purchase code to unlock update checks, installs and support.') }}
            </p>

            @can('project-updater-manage')
                <form id="pu-activate-form" action="{{ route('admin.app.updater.activate') }}" method="POST" class="pu-form">
                    @csrf
                    <label class="pu-form__label" for="pu-license-input">{{ __('Envato Purchase Code') }}</label>
                    <input id="pu-license-input"
                        name="purchase_code"
                        type="text"
                        class="pu-input pu-mono"
                        dir="ltr"
                        value="{{ old('purchase_code') }}"
                        placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX"
                        maxlength="40"
                        autocomplete="off">
                    @error('purchase_code')
                        <div class="pu-form__hint pu-form__hint--error">{{ $message }}</div>
                    @else
                        <div id="pu-license-hint" class="pu-form__hint">
                            {{ __('Find your purchase code in your Envato downloads.') }}
                        </div>
                    @enderror

                    <button type="submit" id="pu-license-activate" class="pu-btn pu-btn--primary pu-btn--block" disabled>
                        <i class="fas fa-key" aria-hidden="true"></i> {{ __('Activate license') }}
                    </button>
                </form>

                <a href="{{ $envatoDownloads }}" target="_blank" rel="noopener noreferrer" class="pu-link pu-link--center">
                    {{ __('Where do I find my purchase code?') }}
                </a>
            @endcan
        </div>
    @endif
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
