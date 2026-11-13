<div class="modal fade um-modal um-modal--sm" id="umResetModal" tabindex="-1" aria-labelledby="umResetTitle" aria-hidden="true" data-um-reset>
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.user.reset-password', $user->id) }}" autocomplete="off">
            @csrf
            <input type="hidden" name="method" value="link" data-um-reset-method>
            <input type="hidden" name="force_change" value="1" data-um-switch-value="force_change">
            <input type="hidden" name="sign_out_all" value="1" data-um-switch-value="sign_out_all">

            <div class="um-modal__head">
                <span class="um-modal__tile"><x-icon name="lock"/></span>
                <div class="um-modal__heading">
                    <h3 class="um-modal__title" id="umResetTitle">{{ __('Reset password') }}</h3>
                    <p class="um-modal__sub">{{ __('For :name · :email', ['name' => $user->name, 'email' => maskSensitive($user->email)]) }}</p>
                </div>
                <button type="button" class="um-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">×</button>
            </div>

            <div class="um-modal__body">
                <div class="um-group">
                    <span class="um-group__label">{{ __('Method') }}</span>
                    <div class="um-options um-options--stack">
                        <button type="button" class="um-option um-option--row um-option--radio is-active" data-um-reset-pick="link">
                            <span class="um-tile um-tile--sm"><x-icon name="mail-notify"/></span>
                            <span class="um-option__copy">
                                <span class="um-option__title">{{ __('Email a reset link') }}<span class="um-badge um-badge--success um-badge--xs">{{ __('Recommended') }}</span></span>
                                <span class="um-option__desc">{{ __('Sends a secure link valid for 60 minutes. The user chooses their own password.') }}</span>
                            </span>
                            <span class="um-radio"></span>
                        </button>
                        <button type="button" class="um-option um-option--row um-option--radio" data-um-reset-pick="manual">
                            <span class="um-tile um-tile--sm"><x-icon name="lock"/></span>
                            <span class="um-option__copy">
                                <span class="um-option__title">{{ __('Set a temporary password') }}</span>
                                <span class="um-option__desc">{{ __('You set the password and share it out-of-band. Use only when email is unavailable.') }}</span>
                            </span>
                            <span class="um-radio"></span>
                        </button>
                    </div>
                </div>

                <div class="um-group um-hidden" data-um-reset-manual>
                    <span class="um-group__label">{{ __('New password') }}<button type="button" class="um-link" data-um-reset-generate>{{ __('Generate strong password') }}</button></span>
                    <div class="um-pw">
                        <input type="password" name="password" class="um-pw__input" minlength="8" maxlength="72" placeholder="{{ __('At least 8 characters') }}" data-um-reset-password>
                        <button type="button" class="um-pw__toggle" data-um-reset-toggle>{{ __('Show') }}</button>
                    </div>
                    <div class="um-strength" data-score="0" data-um-reset-strength>
                        <div class="um-strength__bars"><span class="um-strength__bar"></span><span class="um-strength__bar"></span><span class="um-strength__bar"></span><span class="um-strength__bar"></span></div>
                        <span class="um-strength__label" data-um-reset-strength-label>{{ __('Strength') }}</span>
                    </div>
                </div>

                <label class="um-switch-row um-hidden" data-um-reset-manual>
                    <button type="button" class="um-switch" role="switch" aria-checked="true" data-um-switch="force_change"></button>
                    <span class="um-switch-row__copy"><span class="um-switch-row__label">{{ __('Require change at next sign-in') }}</span><span class="um-switch-row__hint">{{ __('The user must set their own password after signing in.') }}</span></span>
                </label>

                <label class="um-switch-row">
                    <button type="button" class="um-switch" role="switch" aria-checked="true" data-um-switch="sign_out_all"></button>
                    <span class="um-switch-row__copy"><span class="um-switch-row__label">{{ __('Sign out of all devices') }}</span><span class="um-switch-row__hint">{{ __('Ends :count active session(s) immediately.', ['count' => $sessionCount]) }}</span></span>
                </label>

                <div class="um-callout um-callout--warning"><x-icon name="shield-ban"/><span data-um-reset-warning
                     data-link="{{ __('The current password keeps working until the user completes the reset. This action is recorded in the audit log.') }}"
                     data-manual="{{ __('Never send temporary passwords over chat or email. This action is recorded in the audit log with your admin ID.') }}">{{ __('The current password keeps working until the user completes the reset. This action is recorded in the audit log.') }}</span></div>
            </div>

            <div class="um-modal__foot">
                <button type="button" class="um-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="um-btn um-btn--primary" data-um-reset-submit data-label-link="{{ __('Send reset link') }}" data-label-manual="{{ __('Set password') }}">{{ __('Send reset link') }}</button>
            </div>
        </form>
    </div>
</div>
