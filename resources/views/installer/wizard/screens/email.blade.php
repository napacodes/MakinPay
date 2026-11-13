<section class="wz-screen" data-wz-screen="6" aria-labelledby="wz-title-email" data-wz-email>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'mail', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-email">{{ __('Outgoing email') }}</h2>
                <p>{{ __('OTP codes, password resets and receipts depend on this. Pick a provider preset or enter SMTP details manually.') }}</p>
            </div>
        </div>
        <button class="wz-btn" type="button" aria-pressed="false" data-wz-mail-skip>
            <span data-wz-mail-skip-icon>@include('installer.wizard.icon', ['name' => 'skip', 'size' => 14])</span>
            <span data-wz-mail-skip-label>{{ __('Skip for now') }}</span>
        </button>
    </div>
    <input type="hidden" name="mail_skip" value="0" data-wz-mail-skip-input>

    <div class="wz-banner wz-banner--warning wz-banner--compact" data-wz-mail-skipped hidden>
        <span class="wz-banner__icon">@include('installer.wizard.icon', ['name' => 'alert', 'size' => 16, 'stroke' => 2.2])</span>
        <div class="wz-banner__copy">
            <span><strong>{{ __('Email is skipped.') }}</strong> {{ __('Users won\'t receive verification codes or password resets until you configure SMTP in Admin → Settings → Email.') }}</span>
        </div>
    </div>

    <div class="wz-providers" role="group" aria-label="{{ __('Mail provider presets') }}">
        @foreach($mailProviders as $providerId => $provider)
            <button class="wz-provider {{ $providerId === 'custom' ? 'is-active' : '' }}" type="button" aria-pressed="{{ $providerId === 'custom' ? 'true' : 'false' }}" data-wz-provider="{{ $providerId }}">
                <span class="wz-provider__icon">@include('installer.wizard.icon', ['name' => ['ses' => 'cloud', 'mailgun' => 'send', 'sendgrid' => 'send', 'custom' => 'server'][$providerId] ?? 'mail', 'size' => 14])</span>
                <span class="wz-provider__label">{{ $provider['label'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="wz-card wz-card--form wz-stack" data-wz-mail-form data-wz-enter="[data-wz-mail-test]">
        <div class="wz-grid-6">
            <div class="wz-field wz-col-3">
                <label class="wz-label" for="mail_host"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'server', 'size' => 14]){{ __('SMTP host') }}</span></label>
                <input class="wz-input wz-input--mono" id="mail_host" name="mail_host" value="{{ old('mail_host') }}" required maxlength="255" autocapitalize="off" spellcheck="false" data-wz-mail-field data-wz-mail-host>
            </div>
            <div class="wz-field wz-col-1">
                <label class="wz-label" for="mail_port">{{ __('Port') }}</label>
                <input class="wz-input wz-input--mono" id="mail_port" name="mail_port" type="number" min="1" max="65535" value="{{ old('mail_port', $defaults['mail_port']) }}" required data-wz-mail-field data-wz-mail-port>
            </div>
            <div class="wz-field wz-col-2">
                <label class="wz-label" for="mail_secure"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'lock', 'size' => 14]){{ __('Encryption') }}</span></label>
                <select class="wz-select" id="mail_secure" name="mail_secure" required data-wz-mail-field data-wz-mail-secure>
                    <option value="tls" @selected(old('mail_secure', $defaults['mail_secure']) === 'tls')>TLS (587)</option>
                    <option value="ssl" @selected(old('mail_secure', $defaults['mail_secure']) === 'ssl')>SSL (465)</option>
                </select>
            </div>
            <div class="wz-field wz-col-3">
                <label class="wz-label" for="mail_username"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'user', 'size' => 14]){{ __('Username') }}</span></label>
                <input class="wz-input" id="mail_username" name="mail_username" value="{{ old('mail_username') }}" required maxlength="255" autocomplete="off" placeholder="noreply@{{ $serverReport['environment']['domain'] }}" data-wz-mail-field>
            </div>
            <div class="wz-field wz-col-3">
                <label class="wz-label" for="mail_password"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'key', 'size' => 14]){{ __('Password / API key') }}</span></label>
                <input class="wz-input" id="mail_password" name="mail_password" type="password" required maxlength="255" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-wz-mail-field>
            </div>
            <div class="wz-field wz-col-3">
                <label class="wz-label" for="email_from_name"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'tag', 'size' => 14]){{ __('From name') }}</span></label>
                <input class="wz-input" id="email_from_name" name="email_from_name" value="{{ old('email_from_name') }}" required maxlength="50" data-wz-mail-field data-wz-mail-from-name>
            </div>
            <div class="wz-field wz-col-3">
                <label class="wz-label" for="email_from_address"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'at', 'size' => 14]){{ __('From address') }}</span></label>
                <input class="wz-input" id="email_from_address" name="email_from_address" type="email" value="{{ old('email_from_address') }}" required maxlength="50" placeholder="noreply@{{ $serverReport['environment']['domain'] }}" data-wz-mail-field data-wz-mail-from>
            </div>
        </div>

        <div class="wz-note wz-note--blue" data-wz-provider-hint hidden>
            @include('installer.wizard.icon', ['name' => 'info', 'size' => 15])
            <span data-wz-provider-hint-text></span>
        </div>

        <div class="wz-test-row">
            <label class="wz-test-row__field" for="mail_test_recipient">
                <span class="wz-test-row__label">@include('installer.wizard.icon', ['name' => 'send', 'size' => 14]){{ __('Send test to') }}</span>
                <input class="wz-input wz-input--sm" id="mail_test_recipient" name="mail_test_recipient" type="email" required maxlength="255" data-wz-mail-field data-wz-mail-recipient>
            </label>
            <button class="wz-btn wz-btn--primary" type="button" data-wz-mail-test>
                @include('installer.wizard.icon', ['name' => 'send', 'size' => 14])
                <span>{{ __('Send test email') }}</span>
            </button>
        </div>

        <div class="wz-status" data-wz-mail-status hidden aria-live="polite">
            <div class="wz-status__main">
                <span class="wz-status__icon">
                    <span class="wz-status__idle">@include('installer.wizard.icon', ['name' => 'mail', 'size' => 16])</span>
                    <span class="wz-status__check">@include('installer.wizard.icon', ['name' => 'check', 'size' => 16, 'stroke' => 3])</span>
                    <span class="wz-status__cross">@include('installer.wizard.icon', ['name' => 'alert', 'size' => 16, 'stroke' => 2.2])</span>
                </span>
                <span class="wz-status__text"></span>
            </div>
        </div>
        <ul class="wz-guidance" data-wz-mail-guidance hidden></ul>
    </div>
</section>
