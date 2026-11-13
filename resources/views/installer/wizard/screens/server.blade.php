<section class="wz-screen" data-wz-screen="1" aria-labelledby="wz-title-server" data-wz-server data-wz-server-total="{{ $serverReport['total_count'] }}">
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'server', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-server">{{ __('Server check') }}</h2>
                <p>{{ __('PHP, required extensions and folder permissions were verified automatically. Anything red must be fixed before continuing.') }}</p>
            </div>
        </div>
        <button class="wz-btn" type="button" data-wz-rerun>
            @include('installer.wizard.icon', ['name' => 'refresh', 'size' => 14])
            <span>{{ __('Re-run checks') }}</span>
        </button>
    </div>

    <div class="wz-grid-4">
        @foreach($serverReport['metrics'] as $metric)
            <div class="wz-metric {{ $metric['ok'] ? '' : (in_array($metric['key'], ['memory', 'upload'], true) ? 'is-warn' : 'is-bad') }}" data-wz-metric="{{ $metric['key'] }}">
                <span class="wz-metric__label">
                    @include('installer.wizard.icon', ['name' => ['php' => 'cpu', 'memory' => 'gauge', 'upload' => 'upload', 'checks' => 'checkCircle'][$metric['key']] ?? 'info', 'size' => 13])
                    {{ $metric['label'] }}
                </span>
                <span class="wz-metric__value" data-wz-metric-value>{{ $metric['value'] }}</span>
                <span class="wz-metric__hint" data-wz-metric-hint>{{ $metric['hint'] }}</span>
            </div>
        @endforeach
    </div>

    <div class="wz-banner wz-banner--danger" data-wz-server-banner @if($serverReport['can_install']) hidden @endif role="alert">
        <span class="wz-banner__icon">@include('installer.wizard.icon', ['name' => 'alert', 'size' => 18, 'stroke' => 2.2])</span>
        <div class="wz-banner__copy">
            <strong data-wz-server-banner-title>{{ trans_choice('{1} :count check failed — installation is blocked|[2,*] :count checks failed — installation is blocked', $serverReport['failed_count'], ['count' => $serverReport['failed_count']]) }}</strong>
            <span>{{ __('Each failed row shows the exact fix. Apply it on the server, then re-run the checks.') }}</span>
        </div>
        <button class="wz-btn wz-btn--sm wz-btn--danger-outline" type="button" data-wz-copy="wz-diagnostics">
            @include('installer.wizard.icon', ['name' => 'copy', 'size' => 13])
            <span data-wz-copy-label>{{ __('Copy diagnostics') }}</span>
        </button>
    </div>
    <textarea id="wz-diagnostics" class="wz-visually-hidden" readonly tabindex="-1" aria-hidden="true" data-wz-diagnostics>{{ $serverReport['diagnostics'] }}</textarea>

    <div class="wz-server-cols">
        <div class="wz-card wz-server-ext" data-wz-ext-card>
            <div class="wz-card__head">
                <span class="wz-card__title">@include('installer.wizard.icon', ['name' => 'cpu', 'size' => 15]){{ __('PHP extensions') }}</span>
                <span class="wz-card__meta" data-wz-ext-summary></span>
            </div>
            <div class="wz-checks" data-wz-ext-rows>
                @foreach($serverReport['extensions'] as $row)
                    @include('installer.wizard.screens.partials.check-row', ['row' => $row, 'withHelp' => true])
                @endforeach
            </div>
        </div>

        <div class="wz-server-right" data-wz-server-right>
            <div class="wz-card">
                <div class="wz-card__head">
                    <span class="wz-card__title">@include('installer.wizard.icon', ['name' => 'folder', 'size' => 15]){{ __('Writable paths') }}</span>
                    <span class="wz-card__meta" data-wz-writable-summary></span>
                </div>
                <div data-wz-writable-rows>
                    @foreach($serverReport['writable'] as $row)
                        @include('installer.wizard.screens.partials.check-row', ['row' => $row, 'withHelp' => ! $row['ok']])
                    @endforeach
                </div>
            </div>

            <div class="wz-terminal">
                <div class="wz-terminal__head">
                    <span class="wz-terminal__label">@include('installer.wizard.icon', ['name' => 'terminal', 'size' => 13]){{ __('Fix via SSH') }}</span>
                    <button class="wz-btn wz-btn--xs wz-btn--ghost-dark" type="button" data-wz-copy="wz-fix-commands">
                        @include('installer.wizard.icon', ['name' => 'copy', 'size' => 12])
                        <span data-wz-copy-label>{{ __('Copy') }}</span>
                    </button>
                </div>
                <code class="wz-terminal__code" id="wz-fix-commands" data-wz-fix-commands>{{ $serverReport['fix_commands'] }}</code>
                <span class="wz-terminal__hint">{{ __('On cPanel: File Manager → Permissions → 775 for the same folders.') }}</span>
            </div>
        </div>
    </div>
</section>
