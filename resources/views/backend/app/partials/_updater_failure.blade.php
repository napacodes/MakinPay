@if($installFailed)
    @php
        /*
         * What the operator has to know before deciding anything: which
         * release failed, what the server said, that the site is back up, and
         * whether the snapshot taken beforehand is still there to fall back on.
         *
         * The retry lives in the header — it is this state's primary action and
         * printing it twice would make the banner compete with its own page.
         */
        $failureBackupOnDisk = $latest->backupIsOnDisk();

        $failureFacts = [
            [
                'label' => __('Failed at'),
                'value' => $latest->updated_at?->translatedFormat('d M Y · H:i') ?? '—',
                'mono'  => false,
            ],
            [
                'label' => __('Backup'),
                'value' => $failureBackupOnDisk
                    ? $latest->backup_path
                    : ($latest->backup_path ? __('Recorded but no longer on disk') : __('None taken')),
                'mono'  => $failureBackupOnDisk,
            ],
            [
                'label' => __('Site status'),
                'value' => __('Online · v:version · maintenance lifted', ['version' => $currentVersion]),
                'mono'  => false,
            ],
        ];
    @endphp

    <section class="pu-card pu-card--danger" data-show="install-failed">
        <div class="pu-fail">
            <span class="pu-fail__icon" aria-hidden="true"><i class="fas fa-circle-exclamation"></i></span>

            <div class="pu-fail__copy">
                <h2 class="pu-fail__title">{{ __('Update to v:version failed', ['version' => $latest->version]) }}</h2>
                <p class="pu-fail__text">
                    {{ $latest->error_message ?: __('The install stopped before it finished.') }}
                    {{ __('Maintenance mode was lifted automatically; the site is running v:version.', ['version' => $currentVersion]) }}
                </p>
            </div>

            <button type="button" class="pu-btn pu-btn--secondary pu-btn--sm pu-fail__action"
                data-action="open-help-modal"
                @if($failureCategory) data-open-category="{{ $failureCategory }}" @endif>
                <i class="fas fa-life-ring" aria-hidden="true"></i> {{ __('How to fix this') }}
            </button>
        </div>

        <div class="pu-strip">
            @foreach($failureFacts as $fact)
                <div class="pu-strip__cell">
                    <span class="pu-strip__k">{{ $fact['label'] }}</span>
                    <span class="pu-strip__v pu-strip__v--sm {{ $fact['mono'] ? 'pu-mono' : '' }}">{{ $fact['value'] }}</span>
                </div>
            @endforeach
        </div>
    </section>
@endif
