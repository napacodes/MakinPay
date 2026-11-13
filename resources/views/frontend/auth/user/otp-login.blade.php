@extends('frontend.layouts.auth')
@section('title', __('Sign in with an SMS code'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/agent.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/agent.css'))) }}">
    <link rel="stylesheet" href="{{ asset('frontend/css/phone-sign-in.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/phone-sign-in.css'))) }}">
@endpush
@section('auth-content')
    <div class="auth-shell">
        <div class="auth-card is-user">

            <a href="{{ route('user.login') }}" class="d-inline-flex align-items-center gap-2 text-decoration-none fw-semibold text-body mb-3">
                <i class="fa-light fa-chevron-left" aria-hidden="true"></i>{{ __('Back to sign in') }}
            </a>

            <div class="auth-head">
                <img src="{{ asset(setting('logo')) }}" alt="Logo" class="auth-logo" loading="lazy">
                @if($login === null)
                    <h4 class="auth-title">{{ __('Sign in with an SMS code') }}</h4>
                    <p class="auth-subtitle">{{ __('Enter the phone number on your account and we will text you a one-time code.') }}</p>
                @else
                    <h4 class="auth-title">{{ __('Check your phone') }}</h4>
                    <p class="auth-subtitle">
                        {{ __('We sent a code to') }}<br>
                        <strong class="text-body">{{ \App\Support\MaskedPhone::forDisplay($login) }}</strong>
                    </p>
                @endif
            </div>

            @if (session('otp_sent'))
                <div class="alert alert-info" role="status" data-otp-sent>{{ session('otp_sent') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-warning" role="alert">
                    @foreach ($errors->all() as $error)
                        <strong>{{ $error }}</strong><br>
                    @endforeach
                </div>
            @endif

            @if ($login === null)
                <form action="{{ route('user.login.otp.send') }}" method="post" data-otp-step="number">
                    @csrf
                    <x-auth.phone-field id="otp-login" :value="old('login')" autofocus />

                    @if(config('services.recaptcha.status'))
                        <div class="g-recaptcha mt-3 mb-3" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
                    @endif

                    <button class="btn btn-primary btn-submit-auth" type="submit">
                        <i class="fa-light fa-message-sms me-1"></i> {{ __('Send me a code') }}
                    </button>
                </form>
            @else
                <form action="{{ route('user.login.otp.verify') }}" method="post" data-otp-step="code">
                    @csrf
                    <input type="hidden" name="login" value="{{ $login }}">

                    <fieldset class="psf-code border-0 p-0 m-0" data-otp-boxes data-otp-length="6" data-otp-digit-label="{{ __('Digit :n') }}">
                        <legend class="form-label fw-semibold fs-6 mb-2">{{ __('Sign-in code') }}</legend>
                        <div class="input-group psf-code__fallback">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="text" name="code" id="otp-code" class="form-control" inputmode="numeric"
                                   autocomplete="one-time-code" maxlength="10" placeholder="{{ __('6-digit code') }}" required autofocus>
                        </div>
                    </fieldset>

                    <div class="psf-code__meta" data-otp-countdown="60">
                        <span data-otp-waiting>{{ __('Resend code in') }} <strong data-otp-time>1:00</strong></span>
                        <span data-otp-ready hidden>{{ __("Didn't get it?") }}</span>
                        <button type="submit" form="otpRestartForm" class="psf-code__link">{{ __('Send a new code, or use another number') }}</button>
                    </div>

                    @if(config('services.recaptcha.status'))
                        <div class="g-recaptcha mt-3 mb-3" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
                    @endif

                    <button class="btn btn-primary btn-submit-auth" type="submit">
                        {{ __('Verify and sign in') }}
                    </button>

                    <p class="psf-code__safety">
                        <i class="fa-light fa-shield-check" aria-hidden="true"></i>
                        <span>{{ __('The code works once and expires in :minutes minutes. :site will never call or message you to ask for it.', ['minutes' => max(1, min(15, (int) setting('otp_login_expires_minutes', 5))), 'site' => setting('site_title')]) }}</span>
                    </p>
                </form>

                <form id="otpRestartForm" action="{{ route('user.login.otp.restart') }}" method="post" class="d-none">
                    @csrf
                </form>
            @endif

            <p class="text-center mt-4 mb-2">
                <a href="{{ route('user.login') }}" class="text-decoration-none text-primary fw-semibold">{{ __('Sign in with your password instead') }}</a>
            </p>
        </div>
    </div>
@endsection
@push('scripts')
    <script src="{{ asset('frontend/js/phone-sign-in.js?v=' . config('app.version') . '-' . filemtime(public_path('frontend/js/phone-sign-in.js'))) }}"></script>
    @if(config('services.recaptcha.status'))
        <script async src="https://www.google.com/recaptcha/api.js"></script>
    @endif
@endpush
