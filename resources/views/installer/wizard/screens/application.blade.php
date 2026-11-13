<section class="wz-screen" data-wz-screen="2" aria-labelledby="wz-title-application" data-wz-application>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'globe', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-application">{{ __('Application') }}</h2>
                <p>{{ __('Identity, admin URL and money defaults. Everything here is editable later from Admin → Settings.') }}</p>
            </div>
        </div>
    </div>

    <div class="wz-card wz-card--form">
        <div class="wz-fields">
            <div class="wz-field">
                <label class="wz-label" for="app_name"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'tag', 'size' => 14]){{ __('Application name') }}</span></label>
                <input class="wz-input" id="app_name" name="app_name" value="{{ old('app_name', $defaults['app_name']) }}" required maxlength="80">
                <span class="wz-help">{{ __('Browser tab, emails and headers.') }}</span>
            </div>

            <div class="wz-field">
                <label class="wz-label" for="app_url">
                    <span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'link', 'size' => 14]){{ __('Application URL') }}</span>
                    <span class="wz-pill wz-pill--green">@include('installer.wizard.icon', ['name' => 'lock', 'size' => 10, 'stroke' => 2.5]){{ __('Auto-detected') }}</span>
                </label>
                <input class="wz-input wz-input--mono wz-input--locked" id="app_url" name="app_url" value="{{ $defaults['app_url'] }}" readonly tabindex="-1" aria-readonly="true" data-wz-app-url>
                <span class="wz-help">{{ __('Locked to the address you\'re installing from.') }}</span>
            </div>

            <div class="wz-field">
                <label class="wz-label" for="admin_prefix"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'hash', 'size' => 14]){{ __('Admin URL prefix') }}</span></label>
                <div class="wz-affix">
                    <span class="wz-affix__addon" aria-hidden="true">/</span>
                    <input class="wz-input wz-input--mono" id="admin_prefix" name="admin_prefix" value="{{ old('admin_prefix', $defaults['admin_prefix']) }}" required minlength="2" maxlength="20" pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" title="{{ __('Lowercase letters, numbers and dashes; start and end with a letter or number.') }}" autocapitalize="off" spellcheck="false" data-wz-prefix>
                    <button class="wz-affix__action" type="button" data-wz-randomize>@include('installer.wizard.icon', ['name' => 'shuffle', 'size' => 12, 'stroke' => 2.2]){{ __('Randomize') }}</button>
                </div>
                <span class="wz-help wz-login-preview">
                    {{ __('Login at') }}
                    <code id="wz-login-url" data-wz-login-url>{{ $serverReport['environment']['domain'] }}/{{ $defaults['admin_prefix'] }}/login</code>
                    <button class="wz-link-button" type="button" data-wz-copy="wz-login-url-full">@include('installer.wizard.icon', ['name' => 'copy', 'size' => 11, 'stroke' => 2.2])<span data-wz-copy-label>{{ __('Copy') }}</span></button>
                    <input type="hidden" id="wz-login-url-full" value="{{ rtrim($defaults['app_url'], '/') }}/{{ $defaults['admin_prefix'] }}/login" data-wz-login-url-full>
                </span>
            </div>

            <div class="wz-field">
                <label class="wz-label" for="default_currency_code"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'dollar', 'size' => 14]){{ __('Default currency') }}</span></label>
                <select class="wz-select" id="default_currency_code" name="default_currency_code" required>
                    @foreach($currencyCatalog as $code => $info)
                        <option value="{{ $code }}" @selected(old('default_currency_code', $defaults['default_currency_code']) === $code)>{{ $code }} — {{ $info['name'] }} ({{ $info['symbol'] }})</option>
                    @endforeach
                </select>
                <span class="wz-help">{{ __('Base wallet currency. Add more later in Admin → Currencies.') }}</span>
            </div>

            <div class="wz-field">
                <label class="wz-label" for="site_timezone"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'pin', 'size' => 14]){{ __('Timezone') }}</span></label>
                <select class="wz-select" id="site_timezone" name="site_timezone" data-wz-timezone data-wz-detected-label="{{ __('detected') }}">
                    @foreach($timezoneCatalog as $zone => $label)
                        <option value="{{ $zone }}" @selected(old('site_timezone', $defaults['site_timezone']) === $zone)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="wz-help">{{ __('Transaction timestamps, reports and scheduled jobs.') }}</span>
            </div>
        </div>
    </div>

    <div class="wz-grid-3">
        <div class="wz-tip">
            <span class="wz-tip__icon">@include('installer.wizard.icon', ['name' => 'hash', 'size' => 15])</span>
            <span class="wz-tip__copy"><strong>{{ __('Admin prefix') }}</strong><span>{{ __('Lowercase letters, numbers and dashes. A custom value hides the login page from bot scanners.') }}</span></span>
        </div>
        <div class="wz-tip">
            <span class="wz-tip__icon">@include('installer.wizard.icon', ['name' => 'dollar', 'size' => 15])</span>
            <span class="wz-tip__copy"><strong>{{ __('Currency') }}</strong><span>{{ __('Base unit for wallets, ledgers and reports. Exchange rates for other currencies are added after install.') }}</span></span>
        </div>
        <div class="wz-tip">
            <span class="wz-tip__icon">@include('installer.wizard.icon', ['name' => 'pin', 'size' => 15])</span>
            <span class="wz-tip__copy"><strong>{{ __('Timezone') }}</strong><span>{{ __('Applied to every date the platform shows. Change it later in Admin → Settings.') }}</span></span>
        </div>
    </div>
</section>
