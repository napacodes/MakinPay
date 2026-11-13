@extends('frontend.layouts.auth')
@section('title', __('User Register'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/agent.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/agent.css'))) }}">
    <link rel="stylesheet" href="{{ asset('frontend/css/phone-sign-in.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/phone-sign-in.css'))) }}">
@endpush
@section('auth-content')

    @php
        $myCurrentLocation = registrationLocation();
        $allCountries      = getCountries();
        $home              = \App\Support\HomeCountry::country();
        $pickedCountry     = strtoupper((string) old('country', $myCurrentLocation['country_code']));
        $livesAway         = $home !== null && $pickedCountry !== '' && $pickedCountry !== $home['code'];
        $dialCode          = $livesAway
            ? (getCountryByCode($pickedCountry)['dial_code'] ?? $myCurrentLocation['dial_code'])
            : $myCurrentLocation['dial_code'];
    @endphp

    <div class="auth-shell is-long">
        <div class="auth-card is-user is-wide">

            <div class="auth-head">
                <img src="{{ asset(setting('logo')) }}" alt="Logo" class="auth-logo" loading="lazy">
                <h4 class="auth-title">{{ __('Create your account') }}</h4>
                <p class="auth-subtitle">{{ __('It takes about a minute. Your phone number becomes your sign-in.') }}</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    @foreach ($errors->all() as $error)
                        <strong>{{ $error }}</strong><br>
                    @endforeach
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <form action="{{ route('user.register') }}" method="POST">
                @csrf

                <h6 class="auth-section-heading">
                    <i class="fas fa-location-dot me-1"></i>{{ __('Where do you live?') }}
                </h6>

                @if($home !== null)
                    <div class="psi-residence" role="radiogroup" aria-label="{{ __('Where do you live?') }}" data-residence data-home="{{ $home['code'] }}">
                        <label class="psi-residence__card {{ $livesAway ? '' : 'is-selected' }}" data-residence-card>
                            <input type="radio" name="residence" value="home" @checked(! $livesAway)>
                            <span class="psf__code">{{ $home['code'] }}</span>
                            <span class="psi-residence__title">{{ __('In :country', ['country' => $home['name']]) }}</span>
                            <span class="psi-residence__desc">{{ __('Local account. Sign in with your number as you dial it — no :dial.', ['dial' => $home['dial_code']]) }}</span>
                        </label>
                        <label class="psi-residence__card {{ $livesAway ? 'is-selected' : '' }}" data-residence-card>
                            <input type="radio" name="residence" value="away" @checked($livesAway)>
                            <span class="psf__code"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                            <span class="psi-residence__title">{{ __('In another country') }}</span>
                            <span class="psi-residence__desc">{{ __('International account. Your number always carries its country code.') }}</span>
                        </label>
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-12" data-residence-picker>
                        <label class="form-label" for="countrySelect">{{ __('Country of residence') }}</label>
                        <select class="form-select" id="countrySelect" name="country" data-residence-select required>
                            <option selected disabled value="">{{ __('Select Country') }}</option>
                            @foreach($allCountries as $country)
                                <option value="{{ strtoupper((string) $country['code']) }}" data-dial-code="{{ $country['dial_code'] ?? '' }}" @selected($pickedCountry == strtoupper((string) $country['code']))>
                                    {{ getCountryDisplayLabel((string) $country['code']) ?? title((string) $country['name']) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <h6 class="auth-section-heading mt-4">
                    <i class="fas fa-user me-1"></i>{{ __('About you') }}
                </h6>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                            <label class="form-label mb-0" for="phone-number">{{ __('Phone Number') }}</label>
                            <span class="psf__pill psf__pill--away">{{ __('Used to sign in') }}</span>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text" id="phone">{{ $dialCode }}</span>
                            <input type="tel" class="form-control" id="phone-number" placeholder="{{ __('Phone') }}" name="phone" value="{{ old('phone') }}" autocomplete="tel-national" inputmode="tel" aria-label="phone" aria-describedby="phone">
                        </div>
                        <small class="text-muted">{{ __("We'll text a code to confirm it's yours.") }}</small>
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="first_name" class="form-label">{{ __('First Name') }}</label>
                        <input type="text" name="first_name" class="form-control" id="first_name" placeholder="{{ __('First Name') }}" required value="{{ old('first_name') }}">
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="last_name" class="form-label">{{ __('Last Name') }}</label>
                        <input type="text" name="last_name" class="form-control" id="last_name" placeholder="{{ __('Last Name') }}" required value="{{ old('last_name') }}">
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="email" class="form-label">{{ __('Email Address') }}</label>
                        <input type="email" name="email" class="form-control" id="email" placeholder="{{ __('Email Address') }}" required value="{{ old('email') }}">
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="username" class="form-label">{{ __('Username') }}</label>
                        <input type="text" name="username" class="form-control" id="username" placeholder="{{ __('Choose a username') }}" required value="{{ old('username') }}">
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="password" class="form-label">{{ __('Password') }}</label>
                        <input type="password" name="password" class="form-control" id="password" placeholder="{{ __('Password') }}" required>
                    </div>
                    <div class="col-md-6 col-12">
                        <label for="password_confirmation" class="form-label">{{ __('Confirm Password') }}</label>
                        <input type="password" name="password_confirmation" class="form-control" id="password_confirmation" placeholder="{{ __('Confirm Password') }}" required>
                    </div>
                </div>

                @if($home !== null)
                    <div class="psi-note {{ $livesAway ? 'is-away' : '' }}" data-residence-note-wrap>
                        <i class="fa-light fa-circle-info" aria-hidden="true"></i>
                        <div>
                            <div data-residence-note="local" @if($livesAway) hidden @endif>
                                <strong>{{ __('You will have a local account') }}</strong>
                                {{ __('Local fees and taxes apply to your transfers.') }}
                            </div>
                            <div data-residence-note="away" @if(! $livesAway) hidden @endif>
                                <strong>{{ __('You will have an international account') }}</strong>
                                {{ __('International fees and taxes apply to your transfers.') }}
                            </div>
                            {{ __('Your country of residence is fixed after sign-up; to change it later, contact support.') }}
                        </div>
                    </div>
                @endif

                @if(config('services.recaptcha.status'))
                    <div class="g-recaptcha mt-3 mb-3" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
                @endif

                <button type="submit" class="btn btn-primary btn-submit-auth mt-4">
                    <i class="fa-light fa-user-plus me-1"></i> {{ __('Create Account') }}
                </button>
            </form>

            <p class="text-center mt-4 mb-2">
                {{ __('Already have a user account?') }}
                <a href="{{ route('user.login') }}" class="text-decoration-none text-primary fw-semibold">{{ __('Sign In') }}</a>
            </p>

            @include('frontend.auth.partials._portal_switch', [
                'current' => 'user',
                'page'    => 'register',
                'heading' => __('Other registrations'),
            ])
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset('frontend/js/phone-sign-in.js?v=' . config('app.version') . '-' . filemtime(public_path('frontend/js/phone-sign-in.js'))) }}"></script>
    @if(config('services.recaptcha.status'))
        <script async src="https://www.google.com/recaptcha/api.js"></script>
    @endif
@endpush
