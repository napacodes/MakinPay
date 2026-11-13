<section class="wz-screen" data-wz-screen="10" aria-labelledby="wz-title-success" data-wz-success>
    <div class="wz-hero wz-hero--success">
        <div class="wz-hero__copy">
            <span class="wz-hero__badge">@include('installer.wizard.icon', ['name' => 'check', 'size' => 26, 'stroke' => 3])</span>
            <h1 id="wz-title-success" class="wz-hero__title">{{ __('DigiKash is installed.') }}</h1>
            <p class="wz-hero__lead">
                <span data-wz-success-stats></span>
                {!! __('The installer is now locked; :path redirects to your site from now on.', ['path' => '<code class="wz-hero__code">/install</code>']) !!}
            </p>
            <div class="wz-hero__actions">
                <a class="wz-btn wz-btn--lg wz-btn--primary" href="#" data-wz-success-admin>@include('installer.wizard.icon', ['name' => 'login', 'size' => 15]){{ __('Open admin panel') }}</a>
                <a class="wz-btn wz-btn--lg wz-btn--light" href="{{ $defaults['app_url'] }}" data-wz-success-site>@include('installer.wizard.icon', ['name' => 'globe', 'size' => 15]){{ __('Visit site') }}</a>
            </div>
        </div>
    </div>

    <div class="wz-grid-2">
        <div class="wz-card wz-card--soft">
            <strong class="wz-card-title">@include('installer.wizard.icon', ['name' => 'lock', 'size' => 14]){{ __('Your admin access') }}</strong>
            <div class="wz-access">
                <div class="wz-access__row">
                    <span class="wz-access__label">@include('installer.wizard.icon', ['name' => 'link', 'size' => 13]){{ __('Login URL') }}</span>
                    <span class="wz-access__value wz-mono" data-wz-success-login></span>
                </div>
                <div class="wz-access__row">
                    <span class="wz-access__label">@include('installer.wizard.icon', ['name' => 'at', 'size' => 13]){{ __('Email') }}</span>
                    <span class="wz-access__value" data-wz-success-email></span>
                </div>
                <div class="wz-access__row">
                    <span class="wz-access__label">@include('installer.wizard.icon', ['name' => 'lock', 'size' => 13]){{ __('Password') }}</span>
                    <span class="wz-access__value is-muted">{{ __('Hidden — the one you set') }}</span>
                </div>
            </div>
            <button class="wz-btn wz-btn--sm" type="button" data-wz-report-download>@include('installer.wizard.icon', ['name' => 'download', 'size' => 13]){{ __('Download install report (.txt)') }}</button>
        </div>

        <div class="wz-card wz-card--soft">
            <strong class="wz-card-title">@include('installer.wizard.icon', ['name' => 'checkCircle', 'size' => 14]){{ __('Recommended next steps') }}</strong>
            <ul class="wz-next-steps">
                <li><span class="wz-next-steps__icon wz-tone-red">@include('installer.wizard.icon', ['name' => 'zap', 'size' => 14])</span><span><strong>{{ __('Set up the cron job') }}</strong><span>{{ __('Payouts and scheduled reports depend on it') }}</span></span></li>
                <li><span class="wz-next-steps__icon wz-tone-amber">@include('installer.wizard.icon', ['name' => 'card', 'size' => 14])</span><span><strong>{{ __('Connect a payment gateway') }}</strong><span>{{ __('So customers can deposit and pay') }}</span></span></li>
                <li><span class="wz-next-steps__icon wz-tone-amber">@include('installer.wizard.icon', ['name' => 'userCheck', 'size' => 14])</span><span><strong>{{ __('Configure KYC') }}</strong><span>{{ __('Decide what customers must verify, and when') }}</span></span></li>
                <li><span class="wz-next-steps__icon wz-tone-green">@include('installer.wizard.icon', ['name' => 'refresh', 'size' => 14])</span><span><strong>{{ __('One-click updates are ready') }}</strong><span>{{ __('The license token was saved for future updates') }}</span></span></li>
            </ul>
        </div>
    </div>

    <div class="wz-security" data-wz-security>
        <span class="wz-security__icon" data-wz-security-icon>@include('installer.wizard.icon', ['name' => 'shield', 'size' => 16])</span>
        <span class="wz-security__text" data-wz-security-text></span>
    </div>
</section>
