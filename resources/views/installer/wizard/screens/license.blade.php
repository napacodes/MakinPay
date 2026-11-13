<section class="wz-screen" data-wz-screen="3" aria-labelledby="wz-title-license" data-wz-license>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'key', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-license">{{ __('Envato license') }}</h2>
                <p>{!! __('Your purchase code is verified with the Coevs update server and bound to :domain. It also unlocks one-click updates later.', ['domain' => '<code class="wz-inline-code">'.e($serverReport['environment']['domain']).'</code>']) !!}</p>
            </div>
        </div>
    </div>

    <div class="wz-duo">
        <div class="wz-card wz-card--form wz-stack">
            <div class="wz-field">
                <label class="wz-label" for="envato_purchase_code"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'key', 'size' => 14]){{ __('Purchase code') }}</span></label>
                <div class="wz-affix" data-wz-license-affix data-wz-enter="[data-wz-license-verify]">
                    <input class="wz-input wz-input--mono" id="envato_purchase_code" name="envato_purchase_code" value="{{ old('envato_purchase_code') }}" required maxlength="80" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocapitalize="off" spellcheck="false" data-wz-license-code>
                    <button class="wz-affix__action" type="button" data-wz-paste>@include('installer.wizard.icon', ['name' => 'clipboardPaste', 'size' => 12, 'stroke' => 2.2]){{ __('Paste') }}</button>
                </div>
                <span class="wz-help">{{ __('Envato → Downloads → License certificate & purchase code.') }}</span>
            </div>

            <div class="wz-status" data-wz-license-status aria-live="polite">
                <div class="wz-status__main">
                    <span class="wz-status__icon">
                        <span class="wz-status__idle">@include('installer.wizard.icon', ['name' => 'shield', 'size' => 16])</span>
                        <span class="wz-status__check">@include('installer.wizard.icon', ['name' => 'check', 'size' => 16, 'stroke' => 3])</span>
                        <span class="wz-status__cross">@include('installer.wizard.icon', ['name' => 'alert', 'size' => 16, 'stroke' => 2.2])</span>
                    </span>
                    <span class="wz-status__text" data-wz-license-text>{{ __('Not verified yet — required to continue') }}</span>
                </div>
                <button class="wz-btn wz-btn--primary" type="button" data-wz-license-verify>
                    @include('installer.wizard.icon', ['name' => 'shield', 'size' => 14])
                    <span>{{ __('Verify license') }}</span>
                </button>
            </div>

            <dl class="wz-details" data-wz-license-details hidden></dl>
            <ul class="wz-guidance" data-wz-license-guidance hidden></ul>
        </div>

        <div class="wz-stack">
            <div class="wz-card wz-card--soft">
                <strong class="wz-card-title">@include('installer.wizard.icon', ['name' => 'info', 'size' => 14]){{ __('How verification works') }}</strong>
                <ol class="wz-steps">
                    <li>{{ __('Your code, domain and item ID are sent over HTTPS to the Coevs update server.') }}</li>
                    <li>{{ __('Envato confirms the purchase; no author token is ever stored on your server.') }}</li>
                    <li>{{ __('A signed license token is saved for future one-click updates.') }}</li>
                </ol>
            </div>
            <div class="wz-note wz-note--amber">
                @include('installer.wizard.icon', ['name' => 'alert', 'size' => 15])
                <span><strong>{{ __('Moving domains?') }}</strong> {{ __('One code covers one production domain. Contact support to release the license from the old domain first.') }}</span>
            </div>
        </div>
    </div>
</section>
