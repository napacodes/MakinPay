@php
    $wizardOptions = [
        ['name' => 'seed_demo_data', 'on' => '1', 'off' => '0', 'checked' => false, 'span' => true, 'icon' => 'users', 'recommended' => true, 'badge' => __('Recommended: off'), 'title' => __('Install demo data'), 'description' => __('Adds sample users, merchants and virtual cards so you can explore the platform. Leave it off for a live site.')],
        ['name' => 'queue_connection', 'on' => 'database', 'off' => 'sync', 'checked' => true, 'span' => false, 'icon' => 'branch', 'recommended' => false, 'badge' => __('Default'), 'title' => __('Database queue driver'), 'description' => __('Notifications, webhooks and emails run in the background. Needs a queue worker (php artisan queue:work); off sends them during the request.')],
        ['name' => 'optimize_caches', 'on' => '1', 'off' => '0', 'checked' => true, 'span' => false, 'icon' => 'gauge', 'recommended' => false, 'badge' => __('Default'), 'title' => __('Cache config & routes'), 'description' => __('Boosts response time. Clear it from Admin after editing .env by hand.')],
        ['name' => 'pwa_enabled', 'on' => '1', 'off' => '0', 'checked' => true, 'span' => false, 'icon' => 'phone', 'recommended' => false, 'badge' => __('Default'), 'title' => __('Enable PWA install prompt'), 'description' => __('Lets users add DigiKash to their home screen with push notifications.')],
    ];
@endphp
<section class="wz-screen" data-wz-screen="7" aria-labelledby="wz-title-options" data-wz-options>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'sliders', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-options">{{ __('Options') }}</h2>
                <p>{{ __('Sensible defaults are pre-selected. Skip straight to review if unsure — all of this can change later.') }}</p>
            </div>
        </div>
    </div>

    <div class="wz-grid-2">
        @foreach($wizardOptions as $option)
            <label class="wz-option {{ $option['span'] ? 'wz-span-all' : '' }}">
                <input type="hidden" name="{{ $option['name'] }}" value="{{ $option['off'] }}">
                <input class="wz-option__box" type="checkbox" name="{{ $option['name'] }}" value="{{ $option['on'] }}" @checked(old($option['name'], $option['checked'] ? $option['on'] : $option['off']) === $option['on']) data-wz-option="{{ $option['name'] }}" data-wz-option-title="{{ $option['title'] }}">
                <span class="wz-option__icon">@include('installer.wizard.icon', ['name' => $option['icon'], 'size' => 17])</span>
                <span class="wz-option__copy">
                    <span class="wz-option__head">
                        <strong>{{ $option['title'] }}</strong>
                        <span class="wz-pill {{ $option['recommended'] ? 'wz-pill--amber' : 'wz-pill--slate' }}">{{ $option['badge'] }}</span>
                    </span>
                    <span class="wz-option__text">{{ $option['description'] }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <div class="wz-terminal wz-terminal--cron">
        <div class="wz-terminal__head">
            <div class="wz-cron__title">
                <span class="wz-cron__icon">@include('installer.wizard.icon', ['name' => 'clock', 'size' => 16])</span>
                <span class="wz-cron__copy">
                    <strong>{{ __('Cron job — required for payouts & scheduled reports') }}</strong>
                    <span>{{ __('Add it to your server crontab, or cPanel → Cron Jobs, to run every minute.') }}</span>
                </span>
            </div>
            <button class="wz-btn wz-btn--xs wz-btn--ghost-dark" type="button" data-wz-copy="wz-cron-command">
                @include('installer.wizard.icon', ['name' => 'copy', 'size' => 12])
                <span data-wz-copy-label>{{ __('Copy') }}</span>
            </button>
        </div>
        <code class="wz-cron__command" id="wz-cron-command">{{ $cronCommand }}</code>
    </div>
</section>
