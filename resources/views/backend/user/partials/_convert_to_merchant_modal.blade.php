<div class="modal fade um-modal" id="umConvertModal" tabindex="-1" aria-labelledby="umConvertTitle" aria-hidden="true" data-um-convert>
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('admin.user.convert-to-merchant', $user->id) }}">
            @csrf
            <input type="hidden" name="notify" value="1" data-um-switch-value="notify_convert">

            <div class="um-modal__head">
                <span class="um-modal__tile"><x-icon name="merchant"/></span>
                <div class="um-modal__heading">
                    <h3 class="um-modal__title" id="umConvertTitle">{{ __('Convert to merchant') }}</h3>
                    <p class="um-modal__sub">{{ __("Switch :name's account type from Personal to Merchant.", ['name' => $user->name]) }}</p>
                </div>
                <button type="button" class="um-modal__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}">×</button>
            </div>

            <div class="um-modal__body um-modal__body--tight">
                <div class="um-compare">
                    <span class="um-compare__side">
                        <span class="um-tile um-tile--sm"><x-icon name="users-1"/></span>
                        <span class="um-compare__cell"><span class="um-compare__label">{{ __('Current') }}</span><span class="um-compare__value">{{ __('Personal') }}</span></span>
                    </span>
                    <span class="um-compare__arrow" aria-hidden="true">→</span>
                    <span class="um-compare__side um-compare__side--end">
                        <span class="um-compare__cell um-compare__cell--end"><span class="um-compare__label">{{ __('New') }}</span><span class="um-compare__value" style="color: var(--um-primary-hover)">{{ __('Merchant') }}</span></span>
                        <span class="um-tile um-tile--sm um-tile--solid"><x-icon name="merchant"/></span>
                    </span>
                </div>

                <div class="um-group">
                    <span class="um-group__label"><span>{{ __('Business name') }} <span class="is-required">*</span></span></span>
                    <input type="text" name="business_name" class="um-input um-input--lg" required minlength="2" maxlength="255" value="{{ old('business_name', $user->business_name) }}" placeholder="{{ __('e.g. Uddin Traders') }}" data-um-convert-name>
                    @error('business_name')<span class="um-field__error">{{ $message }}</span>@enderror
                </div>

                <div class="um-group">
                    <span class="um-group__label">{{ __('What changes') }}</span>
                    <div class="um-ledger">
                        @foreach([
                            [__('Merchant dashboard, payment links and QR checkout are enabled'), true],
                            [__('Personal-only features (mobile recharge) are turned off'), false],
                            [__('Merchant fee schedule applies to incoming payments'), true],
                            [__('Role-based permissions switch to the Merchant set'), true],
                        ] as [$effect, $positive])
                            <div class="um-ledger__row um-ledger__row--check"><span class="um-check {{ $positive ? 'um-check--on' : '' }}"><x-icon name="check"/></span><span>{{ $effect }}</span></div>
                        @endforeach
                    </div>
                </div>

                <label class="um-switch-row">
                    <button type="button" class="um-switch" role="switch" aria-checked="true" data-um-switch="notify_convert"></button>
                    <span class="um-switch-row__copy"><span class="um-switch-row__label">{{ __('Notify the user by email') }}</span><span class="um-switch-row__hint">{{ __('Sends a "Your account is now a merchant account" message.') }}</span></span>
                </label>

                <div class="um-callout"><x-icon name="shield"/><span>{{ __('Wallet balances, transaction history and KYC status are kept. Merchant KYC may be required before accepting payments.') }}</span></div>
            </div>

            <div class="um-modal__foot">
                <button type="button" class="um-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="um-btn um-btn--primary" disabled data-um-convert-submit>{{ __('Convert to merchant') }}</button>
            </div>
        </form>
    </div>
</div>
