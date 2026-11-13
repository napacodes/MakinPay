@extends('backend.user.manage')

@section('user_manage_content')
    {{-- Username and email are shown once, read-only; the rest posts to the existing update-info action. --}}
    @php
        /*
         * PH7: the countries a customer may sign up with, by name, for the
         * country select. Tax rules read local or international from the
         * registered country, so the select asks before it changes.
         */
        $umSelectable = array_flip(\App\Support\HomeCountry::selectableCodes());
        $umCountries  = [];

        foreach (getCountries() as $umCountry) {
            $umCode = strtoupper((string) ($umCountry['code'] ?? ''));

            if (isset($umSelectable[$umCode])) {
                $umCountries[$umCode] = trim(title((string) ($umCountry['name'] ?? $umCode)).' ('.$umCode.')');
            }
        }

        asort($umCountries);

        $umCurrentCountry = strtoupper((string) old('country', $user->country));
    @endphp
    <form class="um-card" method="POST" action="{{ route('admin.user.update-info', $user->id) }}" data-um-info>
        @csrf
        @method('PUT')

        <div class="um-form__section">
            <div class="um-form__intro">
                <h3>{{ __('Identity') }}</h3>
                <p>{{ __('Username and email are managed by the user and shown in the header.') }}</p>
            </div>
            <div class="um-form__grid">
                <label class="um-field">
                    <span class="um-field__label">{{ __('First name') }}</span>
                    <input type="text" name="first_name" class="um-input" maxlength="50" value="{{ old('first_name', $user->first_name) }}" placeholder="{{ __('First name') }}">
                    @error('first_name')<span class="um-field__error">{{ $message }}</span>@enderror
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('Last name') }}</span>
                    <input type="text" name="last_name" class="um-input" maxlength="50" value="{{ old('last_name', $user->last_name) }}" placeholder="{{ __('Last name') }}">
                    @error('last_name')<span class="um-field__error">{{ $message }}</span>@enderror
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('Gender') }}</span>
                    <select name="gender" class="um-input">
                        @foreach(\App\Enums\Gender::cases() as $gender)
                            <option value="{{ $gender->value }}" @selected(old('gender', $user->gender?->value) === $gender->value)>{{ $gender->label() }}</option>
                        @endforeach
                    </select>
                    @error('gender')<span class="um-field__error">{{ $message }}</span>@enderror
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('Date of birth') }}</span>
                    <input type="date" name="birthday" class="um-input" value="{{ old('birthday', $user->birthday) }}" max="{{ now()->subDay()->toDateString() }}">
                    @error('birthday')<span class="um-field__error">{{ $message }}</span>@enderror
                </label>
                @if($user->isMerchant())
                    <label class="um-field">
                        <span class="um-field__label">{{ __('Business name') }}</span>
                        <input type="text" name="business_name" class="um-input" maxlength="255" value="{{ old('business_name', $user->business_name) }}" placeholder="{{ __('Business name') }}">
                    </label>
                    <label class="um-field">
                        <span class="um-field__label">{{ __('Business address') }}</span>
                        <input type="text" name="business_address" class="um-input" maxlength="255" value="{{ old('business_address', $user->business_address) }}" placeholder="{{ __('Business address') }}">
                    </label>
                @endif
            </div>
        </div>

        <div class="um-form__section">
            <div class="um-form__intro">
                <h3>{{ __('Contact & address') }}</h3>
                <p>{{ __('The country is set at registration and the user cannot change it. Correct it here only for a registration mistake: tax rules read local or international from it.') }}</p>
            </div>
            <div class="um-form__grid">
                <label class="um-field">
                    <span class="um-field__label">{{ __('Phone') }}</span>
                    <input type="text" name="phone" class="um-input" maxlength="20" dir="ltr" value="{{ old('phone', maskSensitive($user->phone, 'phone')) }}" placeholder="{{ __('Phone') }}">
                    @error('phone')<span class="um-field__error">{{ $message }}</span>@enderror
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('Country') }}<span class="um-field__tag">{{ __('Tax: local or international') }}</span></span>
                    <select name="country" class="um-input" data-um-country
                            data-um-original="{{ strtoupper((string) $user->country) }}"
                            data-um-confirm="{{ __('Change this account\'s country? Tax rules read local or international from it, and the user cannot change it back. Do this only to correct a registration mistake.') }}">
                        @if($umCurrentCountry !== '' && ! isset($umCountries[$umCurrentCountry]))
                            <option value="{{ $umCurrentCountry }}" selected>{{ $umCurrentCountry }}</option>
                        @endif
                        @foreach($umCountries as $umCode => $umName)
                            <option value="{{ $umCode }}" @selected($umCurrentCountry === $umCode)>{{ $umName }}</option>
                        @endforeach
                    </select>
                    @error('country')<span class="um-field__error">{{ $message }}</span>@enderror
                    @if($user->country_corrected_at)
                        <span class="um-field__hint" data-um-country-corrected>
                            {{ __('Corrected from :from by :admin on :date.', [
                                'from'  => $user->country_corrected_from ?: __('none'),
                                'admin' => $user->countryCorrectedBy?->name ?: __('an admin'),
                                'date'  => $user->country_corrected_at->format('d M Y, H:i'),
                            ]) }}
                        </span>
                    @endif
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('State / division') }}</span>
                    <input type="text" name="state" class="um-input" value="{{ old('state', $user->state) }}" placeholder="{{ __('State / division') }}">
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('City') }}</span>
                    <input type="text" name="city" class="um-input" value="{{ old('city', $user->city) }}" placeholder="{{ __('City') }}">
                </label>
                <label class="um-field">
                    <span class="um-field__label">{{ __('Postal code') }}</span>
                    <input type="text" name="postal_code" class="um-input" maxlength="20" value="{{ old('postal_code', $user->postal_code) }}" placeholder="{{ __('Postal code') }}">
                </label>
                <label class="um-field um-field--span">
                    <span class="um-field__label">{{ __('Street address') }}</span>
                    <input type="text" name="address" class="um-input" maxlength="255" value="{{ old('address', $user->address) }}" placeholder="{{ __('Street address') }}">
                    @error('address')<span class="um-field__error">{{ $message }}</span>@enderror
                </label>
            </div>
        </div>

        <div class="um-card__foot">
            <button type="reset" class="um-btn">{{ __('Discard') }}</button>
            <button type="submit" class="um-btn um-btn--primary">{{ __('Save changes') }}</button>
        </div>
    </form>
@endsection
