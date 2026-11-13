<section class="wz-screen" data-wz-screen="5" aria-labelledby="wz-title-admin" data-wz-admin>
    <div class="wz-head">
        <div class="wz-head__identity">
            <span class="wz-head__icon">@include('installer.wizard.icon', ['name' => 'user', 'size' => 22])</span>
            <div class="wz-head__copy">
                <h2 id="wz-title-admin">{{ __('Super admin account') }}</h2>
                <p>{{ __('This account controls everything — payouts, KYC approvals, gateway keys. Make it strong and unique.') }}</p>
            </div>
        </div>
    </div>

    <div class="wz-duo wz-duo--wide">
        <div class="wz-card wz-card--form wz-stack">
            <div class="wz-fields">
                <div class="wz-field">
                    <label class="wz-label" for="admin_name"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'user', 'size' => 14]){{ __('Full name') }}</span></label>
                    <input class="wz-input" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required maxlength="120" autocomplete="name">
                </div>
                <div class="wz-field">
                    <label class="wz-label" for="admin_email"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'at', 'size' => 14]){{ __('Email') }}</span></label>
                    <input class="wz-input" id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required maxlength="255" autocomplete="email" placeholder="admin@{{ $serverReport['environment']['domain'] }}" data-wz-admin-email>
                </div>
                <div class="wz-field">
                    <label class="wz-label" for="admin_password">
                        <span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'lock', 'size' => 14]){{ __('Password') }}</span>
                        <button class="wz-link-button" type="button" title="{{ __('Generate strong password') }}" data-wz-generate>@include('installer.wizard.icon', ['name' => 'wand', 'size' => 12, 'stroke' => 2.2]){{ __('Generate') }}</button>
                    </label>
                    <div class="wz-affix">
                        <input class="wz-input wz-input--mono" id="admin_password" name="admin_password" type="password" required minlength="8" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-wz-password>
                        <button class="wz-affix__action" type="button" data-wz-reveal aria-pressed="false">
                            <span data-wz-reveal-icon>@include('installer.wizard.icon', ['name' => 'eye', 'size' => 13])</span>
                            <span data-wz-reveal-label>{{ __('Show') }}</span>
                        </button>
                    </div>
                    <div class="wz-strength" data-wz-strength data-level="0">
                        <span class="wz-strength__bars" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
                        <span class="wz-strength__label" data-wz-strength-label>{{ __('Enter a password') }}</span>
                    </div>
                </div>
                <div class="wz-field">
                    <label class="wz-label" for="admin_password_confirmation"><span class="wz-label__text">@include('installer.wizard.icon', ['name' => 'shield', 'size' => 14]){{ __('Confirm password') }}</span></label>
                    <input class="wz-input wz-input--mono" id="admin_password_confirmation" name="admin_password_confirmation" type="password" required minlength="8" autocomplete="new-password" placeholder="{{ __('Repeat password') }}" data-lpignore="true" data-1p-ignore="true" data-wz-password-confirm>
                    <span class="wz-error" data-wz-password-error hidden></span>
                </div>
            </div>

            <ul class="wz-rules" aria-live="polite">
                <li data-wz-rule="length">{{ __('8+ characters') }}</li>
                <li data-wz-rule="case">{{ __('Upper and lower case') }}</li>
                <li data-wz-rule="number">{{ __('A number') }}</li>
                <li data-wz-rule="match">{{ __('Both fields match') }}</li>
            </ul>
        </div>

        <div class="wz-stack">
            <div class="wz-card wz-card--soft">
                <strong class="wz-card-title">@include('installer.wizard.icon', ['name' => 'shield', 'size' => 14]){{ __('After the first sign-in') }}</strong>
                <ol class="wz-steps">
                    <li>{{ __('Turn on two-factor authentication from your admin profile.') }}</li>
                    <li>{{ __('Invite the rest of your team as admins with their own roles.') }}</li>
                    <li>{{ __('Keep this super admin for emergencies only.') }}</li>
                </ol>
            </div>
            <div class="wz-note wz-note--blue">
                @include('installer.wizard.icon', ['name' => 'info', 'size' => 15])
                <span>{{ __('Store the password in a password manager now — it is never emailed and cannot be recovered without database access.') }}</span>
            </div>
        </div>
    </div>
</section>
