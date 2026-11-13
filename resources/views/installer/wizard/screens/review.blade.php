@php
    $wizardReviewCards = [
        ['key' => 'application', 'title' => __('Application'), 'icon' => 'globe', 'tone' => 'wz-tone-blue', 'step' => 2, 'wide' => false],
        ['key' => 'database', 'title' => __('Database'), 'icon' => 'database', 'tone' => 'wz-tone-violet', 'step' => 4, 'wide' => false],
        ['key' => 'admin', 'title' => __('Super admin'), 'icon' => 'user', 'tone' => 'wz-tone-green', 'step' => 5, 'wide' => false],
        ['key' => 'email', 'title' => __('Email'), 'icon' => 'mail', 'tone' => 'wz-tone-cyan', 'step' => 6, 'wide' => false],
        ['key' => 'options', 'title' => __('Options & data'), 'icon' => 'sliders', 'tone' => 'wz-tone-amber', 'step' => 7, 'wide' => true],
    ];
    $wizardPlan = [
        ['icon' => 'shield', 'text' => __('Re-verify the Envato purchase code')],
        ['icon' => 'fileText', 'text' => __('Write .env and generate APP_KEY')],
        ['icon' => 'database', 'text' => __('Create the database and import the schema')],
        ['icon' => 'layers', 'text' => __('Seed and reconcile reference data')],
        ['icon' => 'user', 'text' => __('Create the super admin and save settings')],
        ['icon' => 'lock', 'text' => __('Clear caches, link storage, lock the installer')],
    ];
@endphp
<section class="wz-screen" data-wz-screen="8" aria-labelledby="wz-title-review" data-wz-review>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'clipboard', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-review">{{ __('Review & install') }}</h2>
                <p>{{ __('Nothing has been written yet. Check each card, then start — installation takes about a minute.') }}</p>
            </div>
        </div>
    </div>

    <div class="wz-readiness">
        @foreach(['server' => ['server', __('Server')], 'license' => ['shield', __('License')], 'database' => ['database', __('Database')], 'email' => ['mail', __('Email')]] as $readyKey => [$readyIcon, $readyLabel])
            <div class="wz-ready" data-wz-ready="{{ $readyKey }}">
                <span class="wz-ready__icon">@include('installer.wizard.icon', ['name' => $readyIcon, 'size' => 16])</span>
                <span class="wz-ready__copy">
                    <span class="wz-ready__label">{{ $readyLabel }}</span>
                    <span class="wz-ready__value" data-wz-ready-value>—</span>
                </span>
            </div>
        @endforeach
    </div>

    <div class="wz-grid-2">
        @foreach($wizardReviewCards as $card)
            <article class="wz-card wz-summary {{ $card['wide'] ? 'wz-span-all' : '' }}" data-wz-summary="{{ $card['key'] }}">
                <header class="wz-summary__head">
                    <span class="wz-summary__title">
                        <span class="wz-summary__icon {{ $card['tone'] }}">@include('installer.wizard.icon', ['name' => $card['icon'], 'size' => 14])</span>
                        {{ $card['title'] }}
                    </span>
                    <button class="wz-link-button" type="button" data-wz-edit="{{ $card['step'] }}">@include('installer.wizard.icon', ['name' => 'edit', 'size' => 12]){{ __('Edit') }}</button>
                </header>
                <dl class="wz-summary__rows" data-wz-summary-rows></dl>
            </article>
        @endforeach
    </div>

    <div class="wz-plan">
        <div class="wz-plan__copy">
            <strong class="wz-plan__title">@include('installer.wizard.icon', ['name' => 'zap', 'size' => 16]){{ __('What happens next') }}</strong>
            <ol class="wz-plan__list">
                @foreach($wizardPlan as $planItem)
                    <li><span class="wz-plan__icon">@include('installer.wizard.icon', ['name' => $planItem['icon'], 'size' => 13])</span>{{ $planItem['text'] }}</li>
                @endforeach
            </ol>
        </div>
        <div class="wz-plan__time">
            <span>{{ __('Estimated') }}</span>
            <strong>~60<small> s</small></strong>
        </div>
    </div>
</section>
