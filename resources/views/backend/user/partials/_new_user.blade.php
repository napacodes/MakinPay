{{--
    Admin → Users → Add new user.

    The dialog behind the "Add user" button on the user list. Four sections
    (Profile, Contact, Security, Access) over the same eleven fields the
    store method has always read — first_name … role, status — so
    UserController::store is untouched; only the shell around the inputs is
    new. Styles live in backend/css/user-create-modal.css (nu-) and the
    behaviour in backend/js/user-create-modal.js; both are progressive, the
    form posts fine with neither.

    After a failed post the page reloads with the modal closed, which used
    to hide the validation errors entirely. `_nu` rides along with the
    input so the reload knows this form was the one that failed, reopens
    it and shows the messages under their fields.
--}}
@php($nuRoleMeta = ['user' => ['user', __('Personal wallet and payments')], 'merchant' => ['shop', __('Accepts payments and checkout')], 'agent' => ['money', __('Cash-in and cash-out desk')]])
@php($nuReopen = old('_nu') === '1' && $errors->any())
@php($nuStatusOn = old('_nu') === '1' ? old('status') === '1' : true)

@push('styles')
    @php($nuCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/user-create-modal.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/user-create-modal.css?v='.$nuCssVersion) }}">
@endpush

@push('scripts')
    @php($nuJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/user-create-modal.js')))
    <script src="{{ asset('backend/js/user-create-modal.js?v='.$nuJsVersion) }}"></script>
@endpush

<div class="modal fade nu-modal" id="new_user_modal" tabindex="-1" aria-labelledby="nuTitle" aria-hidden="true" data-nu-reopen="{{ $nuReopen ? '1' : '0' }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content nu-form" action="{{ route('admin.user.store') }}" method="post" enctype="multipart/form-data" data-nu-form>
            @csrf
            <input type="hidden" name="_nu" value="1">

            <div class="nu-head">
                <span class="nu-head__tile" aria-hidden="true"><x-icon name="kpi-user-plus"/></span>
                <div class="nu-head__copy">
                    <h2 class="nu-head__title" id="nuTitle">{{ __('Add new user') }}</h2>
                    <p class="nu-head__sub">{{ __('Create an account for a customer, merchant or agent.') }}</p>
                </div>
                <button type="button" class="nu-head__close" data-coreui-dismiss="modal" aria-label="{{ __('Close') }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
            </div>

            <div class="modal-body nu-body">
                <section class="nu-section" aria-labelledby="nuSectionProfile">
                    <h3 class="nu-section__head" id="nuSectionProfile">{{ __('Profile') }}</h3>
                    <div class="nu-profile">
                        <div class="nu-avatar">
                            <div class="nu-avatar__frame">
                                <div class="nu-avatar__preview" id="nuAvatarPreview" data-nu-avatar-preview aria-hidden="true"><x-icon name="user"/></div>
                                <input type="file" class="imageUpload nu-avatar__input" name="avatar" id="nuAvatarInput" data-preview-id="nuAvatarPreview" data-nu-avatar accept=".png,.jpg,.jpeg,.gif,.svg" aria-describedby="nuAvatarHint">
                                <label for="nuAvatarInput" class="nu-avatar__badge" aria-label="{{ __('Upload photo') }}"><x-icon name="upload"/></label>
                            </div>
                            <label for="nuAvatarInput" class="nu-avatar__cta">{{ __('Upload photo') }}</label>
                            <span class="nu-avatar__hint" id="nuAvatarHint">{{ __('PNG, JPG, GIF or SVG, up to 2 MB') }}</span>
                            @error('avatar')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="nu-grid nu-grid--2">
                            <div class="nu-field">
                                <label class="nu-label" for="nuFirstName">{{ __('First Name') }} <span class="nu-req" aria-hidden="true">*</span></label>
                                <input class="nu-input" type="text" id="nuFirstName" name="first_name" value="{{ old('first_name') }}" placeholder="{{ __('First Name') }}" autocomplete="given-name" maxlength="{{ App\Support\IdentityRules::NAME_MAX }}" required>
                                @error('first_name')<span class="nu-error">{{ $message }}</span>@enderror
                            </div>
                            <div class="nu-field">
                                <label class="nu-label" for="nuLastName">{{ __('Last Name') }} <span class="nu-req" aria-hidden="true">*</span></label>
                                <input class="nu-input" type="text" id="nuLastName" name="last_name" value="{{ old('last_name') }}" placeholder="{{ __('Last Name') }}" autocomplete="family-name" maxlength="{{ App\Support\IdentityRules::NAME_MAX }}" required>
                                @error('last_name')<span class="nu-error">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="nu-section" aria-labelledby="nuSectionContact">
                    <h3 class="nu-section__head" id="nuSectionContact">{{ __('Contact') }}</h3>
                    <div class="nu-grid nu-grid--2">
                        <div class="nu-field">
                            <label class="nu-label" for="nuUsername">{{ __('Username') }} <span class="nu-req" aria-hidden="true">*</span></label>
                            <div class="nu-adorn">
                                <span class="nu-adorn__prefix" aria-hidden="true">&#64;</span>
                                <input class="nu-adorn__input" type="text" id="nuUsername" name="username" value="{{ old('username') }}" placeholder="{{ __('Username') }}" autocomplete="username" minlength="{{ App\Support\IdentityRules::USERNAME_MIN }}" maxlength="{{ App\Support\IdentityRules::USERNAME_MAX }}" aria-describedby="nuUsernameHint" required>
                            </div>
                            <span class="nu-hint" id="nuUsernameHint">{{ __('Letters, numbers, dots, underscores and hyphens.') }}</span>
                            @error('username')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="nu-field">
                            <label class="nu-label" for="nuEmail">{{ __('Email') }} <span class="nu-req" aria-hidden="true">*</span></label>
                            <div class="nu-adorn">
                                <span class="nu-adorn__lead" aria-hidden="true"><x-icon name="mail"/></span>
                                <input class="nu-adorn__input" type="email" id="nuEmail" name="email" value="{{ old('email') }}" placeholder="{{ __('Email') }}" autocomplete="email" maxlength="{{ App\Support\IdentityRules::EMAIL_MAX }}" aria-describedby="nuEmailHint" required>
                            </div>
                            <span class="nu-hint" id="nuEmailHint">{{ __('Used to sign in and for notifications.') }}</span>
                            @error('email')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="nu-field">
                            <label class="nu-label" for="countrySelect">{{ __('Country') }} <span class="nu-req" aria-hidden="true">*</span></label>
                            <div class="nu-select">
                                <select class="nu-input nu-select__control" id="countrySelect" name="country" data-nu-country required>
                                    <option value="" disabled @selected(old('country') === null)>{{ __('Select Country') }}</option>
                                    @foreach(getCountries() as $country)
                                        <option value="{{ strtoupper((string) $country['code']) }}" data-dial-code="{{ $country['dial_code'] ?? '' }}" @selected(old('country') === strtoupper((string) $country['code']))>
                                            {{ getCountryDisplayLabel((string) $country['code']) ?? title((string) $country['name']) }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="nu-select__caret" aria-hidden="true"><x-icon name="angle-down"/></span>
                            </div>
                            @error('country')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="nu-field">
                            <label class="nu-label" for="nuPhone">{{ __('Phone Number') }} <span class="nu-req" aria-hidden="true">*</span></label>
                            <div class="nu-adorn">
                                <span class="nu-adorn__prefix" data-nu-dial-code dir="ltr" aria-hidden="true"></span>
                                <input class="nu-adorn__input" type="tel" id="nuPhone" name="phone" value="{{ old('phone') }}" placeholder="{{ __('Phone Number') }}" autocomplete="tel-national" aria-describedby="nuPhoneHint" required>
                            </div>
                            <span class="nu-hint" id="nuPhoneHint">{{ __('Dial code follows the selected country.') }}</span>
                            @error('phone')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </section>

                <section class="nu-section" aria-labelledby="nuSectionSecurity">
                    <h3 class="nu-section__head" id="nuSectionSecurity">{{ __('Security') }}</h3>
                    <div class="nu-grid nu-grid--2">
                        <div class="nu-field">
                            <div class="nu-label-row">
                                <label class="nu-label" for="nuPassword">{{ __('Password') }} <span class="nu-req" aria-hidden="true">*</span></label>
                                <button type="button" class="nu-link" data-nu-generate><x-icon name="sparkles"/>{{ __('Generate') }}</button>
                            </div>
                            <div class="nu-adorn">
                                <input class="nu-adorn__input" type="password" id="nuPassword" name="password" placeholder="{{ __('Password') }}" autocomplete="new-password" aria-describedby="nuPasswordHint" data-nu-password required>
                                <button type="button" class="nu-adorn__btn" data-nu-toggle="nuPassword" data-label-show="{{ __('Show password') }}" data-label-hide="{{ __('Hide password') }}" aria-label="{{ __('Show password') }}" aria-pressed="false"><x-icon name="eye" class="nu-eye"/><x-icon name="eye-off" class="nu-eye-off"/></button>
                            </div>
                            <div class="nu-meter" data-nu-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                            <span class="nu-hint nu-strength" id="nuPasswordHint" data-nu-strength data-score="0" data-label-empty="{{ __('Use 8+ characters with a number and a symbol.') }}" data-label-weak="{{ __('Weak') }}" data-label-fair="{{ __('Fair') }}" data-label-good="{{ __('Good') }}" data-label-strong="{{ __('Strong') }}" data-label-summary="{{ __(':strength · :count characters') }}" aria-live="polite">{{ __('Use 8+ characters with a number and a symbol.') }}</span>
                            @error('password')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="nu-field">
                            <label class="nu-label" for="nuConfirmPassword">{{ __('Confirm Password') }} <span class="nu-req" aria-hidden="true">*</span></label>
                            <div class="nu-adorn">
                                <input class="nu-adorn__input" type="password" id="nuConfirmPassword" name="confirm_password" placeholder="{{ __('Confirm Password') }}" autocomplete="new-password" aria-describedby="nuConfirmHint" data-nu-confirm required>
                                <button type="button" class="nu-adorn__btn" data-nu-toggle="nuConfirmPassword" data-label-show="{{ __('Show password') }}" data-label-hide="{{ __('Hide password') }}" aria-label="{{ __('Show password') }}" aria-pressed="false"><x-icon name="eye" class="nu-eye"/><x-icon name="eye-off" class="nu-eye-off"/></button>
                            </div>
                            <span class="nu-hint" id="nuConfirmHint" data-nu-match data-label-empty="{{ __('Re-enter the password to confirm it.') }}" data-label-match="{{ __('Passwords match') }}" data-label-mismatch="{{ __("Passwords don't match yet.") }}" aria-live="polite">{{ __('Re-enter the password to confirm it.') }}</span>
                            @error('confirm_password')<span class="nu-error">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </section>

                <section class="nu-section" aria-labelledby="nuSectionAccess">
                    <h3 class="nu-section__head" id="nuSectionAccess">{{ __('Access') }}</h3>
                    <fieldset class="nu-fieldset">
                        <legend class="nu-label">{{ __('User Type') }} <span class="nu-req" aria-hidden="true">*</span></legend>
                        <div class="nu-grid nu-grid--roles">
                            @foreach(App\Enums\UserRole::cases() as $role)
                                @php($nuMeta = $nuRoleMeta[$role->value] ?? ['user', ''])
                                <label class="nu-role">
                                    <input class="nu-role__input" type="radio" name="role" value="{{ $role->value }}" @checked(old('role', 'user') === $role->value) required>
                                    <span class="nu-role__card">
                                        <span class="nu-role__tile" aria-hidden="true"><x-icon :name="$nuMeta[0]"/></span>
                                        <span class="nu-role__copy">
                                            <span class="nu-role__name">{{ $role->title() }}</span>
                                            <span class="nu-role__desc">{{ $nuMeta[1] }}</span>
                                        </span>
                                        <span class="nu-role__check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('role')<span class="nu-error">{{ $message }}</span>@enderror
                    </fieldset>

                    <div class="nu-switch-row">
                        <div class="form-check form-switch nu-switch-row__control">
                            <input class="form-check-input coevs-switch" type="checkbox" role="switch" id="nuStatus" name="status" value="1" aria-describedby="nuStatusHint" data-nu-status @checked($nuStatusOn)>
                        </div>
                        <div class="nu-switch-row__copy">
                            <label class="nu-switch-row__label" for="nuStatus">{{ __('Account active') }}</label>
                            <span class="nu-switch-row__hint" id="nuStatusHint">{{ __('Off keeps the account locked until an admin turns it on.') }}</span>
                        </div>
                        <span class="nu-pill {{ $nuStatusOn ? 'is-on' : '' }}" data-nu-status-pill data-label-on="{{ __('Active') }}" data-label-off="{{ __('Inactive') }}">{{ $nuStatusOn ? __('Active') : __('Inactive') }}</span>
                    </div>
                </section>
            </div>

            <div class="nu-foot">
                <span class="nu-foot__note">{{ __('Fields marked with * are required.') }}</span>
                <div class="nu-foot__actions">
                    <button type="button" class="nu-btn" data-coreui-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="nu-btn nu-btn--primary"><x-icon name="check"/>{{ __('Create User') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
