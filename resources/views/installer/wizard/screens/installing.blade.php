@php
    $wizardStageTiles = [
        'verify'      => ['icon' => 'shield', 'title' => __('Verifying license'), 'subtitle' => __('Purchase code and mail re-checked')],
        'environment' => ['icon' => 'fileText', 'title' => __('Writing environment'), 'subtitle' => __('.env and APP_KEY')],
        'database'    => ['icon' => 'database', 'title' => __('Preparing database'), 'subtitle' => __('Create and check empty')],
        'import'      => ['icon' => 'upload', 'title' => __('Importing SQL'), 'subtitle' => __('Schema and migrations')],
        'seed'        => ['icon' => 'layers', 'title' => __('Seeding & admin'), 'subtitle' => __('Reference data, super admin')],
        'finish'      => ['icon' => 'lock', 'title' => __('Finishing'), 'subtitle' => __('Cache, storage link, lock')],
    ];
@endphp
<section class="wz-screen" data-wz-screen="9" aria-labelledby="wz-title-installing" data-wz-installing>
    <div class="wz-installing-head">
        <span class="wz-installing-head__icon" data-wz-install-icon><span class="wz-installing-head__spinner"></span></span>
        <div class="wz-head__copy">
            <h2 id="wz-title-installing" data-wz-install-title>{{ __('Installing DigiKash…') }}</h2>
            <p data-wz-install-lead>{{ __('Keep this tab open. Large SQL imports can take a moment on shared hosting.') }}</p>
        </div>
    </div>

    <div class="wz-card wz-card--form wz-stack">
        <div class="wz-install-progress">
            <div class="wz-install-progress__meta">
                <span data-wz-install-stage>{{ __('Starting installation') }}</span>
                <span class="wz-install-progress__value" data-wz-install-percent>0%</span>
            </div>
            <div class="wz-install-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-wz-install-bar>
                <div class="wz-install-progress__fill" data-wz-install-fill></div>
            </div>
        </div>
        <ol class="wz-stages">
            @foreach($installStages as $group)
                @continue(! isset($wizardStageTiles[$group]))
                <li class="wz-stage" data-wz-stage="{{ $group }}">
                    <span class="wz-stage__icon">
                        <span class="wz-stage__glyph">@include('installer.wizard.icon', ['name' => $wizardStageTiles[$group]['icon'], 'size' => 14])</span>
                        <span class="wz-stage__done">@include('installer.wizard.icon', ['name' => 'check', 'size' => 14, 'stroke' => 3])</span>
                        <span class="wz-stage__failed">@include('installer.wizard.icon', ['name' => 'cross', 'size' => 14, 'stroke' => 3])</span>
                    </span>
                    <span class="wz-stage__copy">
                        <strong>{{ $wizardStageTiles[$group]['title'] }}</strong>
                        <span>{{ $wizardStageTiles[$group]['subtitle'] }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
    </div>

    <div class="wz-install-error" data-wz-install-error hidden role="alert">
        <div class="wz-banner wz-banner--danger">
            <span class="wz-banner__icon">@include('installer.wizard.icon', ['name' => 'alert', 'size' => 18, 'stroke' => 2.2])</span>
            <div class="wz-banner__copy">
                <strong>{{ __('Installation could not finish') }}</strong>
                <span data-wz-install-error-text></span>
            </div>
        </div>
        <ul class="wz-guidance" data-wz-install-guidance hidden></ul>
        <div class="wz-install-error__actions">
            <button class="wz-btn" type="button" data-wz-install-fix hidden>@include('installer.wizard.icon', ['name' => 'edit', 'size' => 14])<span data-wz-install-fix-label>{{ __('Fix it') }}</span></button>
            <button class="wz-btn" type="button" data-wz-install-review>@include('installer.wizard.icon', ['name' => 'arrowLeft', 'size' => 14]){{ __('Back to review') }}</button>
            <button class="wz-btn wz-btn--primary" type="button" data-wz-install-retry>@include('installer.wizard.icon', ['name' => 'refresh', 'size' => 14]){{ __('Retry installation') }}</button>
        </div>
    </div>

    <div class="wz-log">
        <div class="wz-log__head">
            <span class="wz-log__title">@include('installer.wizard.icon', ['name' => 'terminal', 'size' => 13]){{ __('Live log') }}</span>
            <button class="wz-btn wz-btn--xs wz-btn--ghost-dark" type="button" data-wz-log-download>@include('installer.wizard.icon', ['name' => 'download', 'size' => 12]){{ __('Download log') }}</button>
        </div>
        <pre class="wz-log__body" data-wz-log aria-live="polite"><span data-wz-log-lines></span><span class="wz-log__cursor" aria-hidden="true">▍</span></pre>
    </div>
</section>
