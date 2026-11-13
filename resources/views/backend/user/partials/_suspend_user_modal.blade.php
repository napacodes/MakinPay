<div class="modal fade um-modal" id="umSuspendModal" tabindex="-1" aria-labelledby="umSuspendTitle" aria-hidden="true" data-um-suspend
     data-i18n-indefinite="{{ __('Stays suspended until an admin lifts it manually.') }}"
     data-i18n-until="{{ __('Auto-lifts on :time (site timezone).', ['time' => '%s']) }}"
     data-i18n-cta-indefinite="{{ __('Suspend account') }}"
     data-i18n-cta-for="{{ __('Suspend for :duration', ['duration' => '%s']) }}">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.user.suspend', $user->id) }}">
            @csrf
            <input type="hidden" name="reason" value="" data-um-suspend-reason>
            <input type="hidden" name="duration" value="7d" data-um-suspend-duration>
            <input type="hidden" name="notify" value="1" data-um-switch-value="notify_suspend">

            <div class="um-modal__head">
                <span class="um-modal__tile um-modal__tile--warning"><x-icon name="shield-ban"/></span>
                <div class="um-modal__heading">
                    <h3 class="um-modal__title" id="umSuspendTitle">{{ __('Suspend account') }}</h3>
                    <p class="um-modal__sub">{{ __('Temporarily block :name from signing in and transacting.', ['name' => $user->name]) }}</p>
                </div>
                <button type="button" class="um-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">×</button>
            </div>

            <div class="um-modal__body um-modal__body--tight">
                @if($user->isDemoProtected())
                    {{--
                        A published demo login. Every visitor to the demo signs
                        in with it and this button sits on a page they can
                        reach, so suspending it would hand the next person a
                        dead sandbox. UserManageController refuses the write;
                        this says so before the form is filled in, and points
                        at the thing that does work — blocking the address.
                    --}}
                    <div class="um-callout um-callout--info">
                        <x-icon name="lock"/>
                        <span>
                            <strong>{{ __('This account cannot be suspended.') }}</strong>
                            {{ __('It is one of the published demo logins, so every visitor holds it. Block the address the trouble came from instead: that stops the one visitor and leaves the demo standing.') }}
                        </span>
                    </div>
                @endif

                <div class="um-group">
                    <span class="um-group__label"><span>{{ __('Reason') }} <span class="is-required">*</span></span></span>
                    <div class="um-chips">
                        @foreach($suspendReasons as $key => $label)
                            <button type="button" class="um-chip um-chip--warning" data-um-suspend-reason-pick="{{ $key }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    @error('reason')<span class="um-field__error">{{ $message }}</span>@enderror
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('Duration') }}</span>
                    <div class="um-seg um-seg--grid" role="group">
                        @foreach($suspendDurations as $key => $label)
                            <button type="button" class="um-seg__btn {{ $key === '7d' ? 'is-active' : '' }}" data-um-suspend-duration-pick="{{ $key }}" data-label="{{ $label }}" data-until="{{ $suspendLiftTimes[$key] ?? '' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <span class="um-field__hint" data-um-suspend-until></span>
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('Internal note') }} <span class="is-optional">({{ __('admins only') }})</span></span>
                    <textarea name="note" rows="2" class="um-input" maxlength="1000" placeholder="{{ __('Context for other admins, ticket IDs, evidence…') }}"></textarea>
                </div>

                <label class="um-switch-row">
                    <button type="button" class="um-switch" role="switch" aria-checked="true" data-um-switch="notify_suspend"></button>
                    <span class="um-switch-row__copy"><span class="um-switch-row__label">{{ __('Notify the user') }}</span><span class="um-switch-row__hint">{{ __('Email explains the suspension and how to appeal via support.') }}</span></span>
                </label>

                <div class="um-callout um-callout--warning"><x-icon name="shield-ban"/><span>{{ __('While suspended: sign-in is blocked, all :sessions active session(s) end, and pending withdrawals (:count · :total) stay on hold. Balances are untouched and you can lift the suspension any time.', ['sessions' => $sessionCount, 'count' => $pendingWithdrawals['count'], 'total' => $pendingWithdrawals['total']]) }}</span></div>
            </div>

            <div class="um-modal__foot">
                <button type="button" class="um-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="um-btn um-btn--warning" disabled data-um-suspend-submit>{{ __('Suspend for :duration', ['duration' => $suspendDurations['7d']]) }}</button>
            </div>
        </form>
    </div>
</div>
