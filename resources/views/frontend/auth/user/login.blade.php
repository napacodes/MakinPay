@extends('frontend.layouts.auth')
@section('title', __('User Login'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/agent.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/agent.css'))) }}">
    <link rel="stylesheet" href="{{ asset('frontend/css/phone-sign-in.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/phone-sign-in.css'))) }}">
@endpush
@section('auth-content')
    @php
        /*
         * The tab the form opens on: the admin's choice, unless the last
         * submission came from the other one - then that, so a failed sign-in
         * comes back to the field the customer was using.
         */
        $openTab = old('login') !== null
            ? (old('login_country') !== null ? 'phone' : 'email')
            : \App\Enums\SignInField::fromSetting()->value;
    @endphp
    <div class="auth-shell">
        <div class="auth-card is-user">

            <div class="auth-head">
                <img src="{{ asset(setting('logo')) }}" alt="Logo" class="auth-logo" loading="lazy">
                <h4 class="auth-title">{{ __('Welcome back') }}</h4>
                <p class="auth-subtitle">{{ __('Sign in to your :site account', ['site' => setting('site_title')]) }}</p>
            </div>

            <form id="userLoginForm" action="{{ route('user.login') }}" method="post">
                @csrf

                <div class="psi-tabs" role="tablist" aria-label="{{ __('Sign in with') }}" data-signin-tabs>
                    <button type="button" class="psi-tabs__tab {{ $openTab === 'phone' ? 'is-active' : '' }}" role="tab"
                            id="signin-tab-phone" aria-controls="signin-panel-phone"
                            aria-selected="{{ $openTab === 'phone' ? 'true' : 'false' }}" tabindex="{{ $openTab === 'phone' ? '0' : '-1' }}"
                            data-signin-tab="phone">
                        <i class="fa-light fa-mobile" aria-hidden="true"></i>{{ __('Phone number') }}
                    </button>
                    <button type="button" class="psi-tabs__tab {{ $openTab === 'email' ? 'is-active' : '' }}" role="tab"
                            id="signin-tab-email" aria-controls="signin-panel-email"
                            aria-selected="{{ $openTab === 'email' ? 'true' : 'false' }}" tabindex="{{ $openTab === 'email' ? '0' : '-1' }}"
                            data-signin-tab="email">
                        <i class="fa-light fa-envelope" aria-hidden="true"></i>{{ __('Email or username') }}
                    </button>
                </div>

                <div id="signin-panel-phone" role="tabpanel" aria-labelledby="signin-tab-phone" data-signin-panel="phone" @if($openTab !== 'phone') hidden @endif>
                    <x-auth.phone-field id="login-phone" :value="$openTab === 'phone' ? old('login') : null" :disabled="$openTab !== 'phone'" />
                </div>

                <div id="signin-panel-email" role="tabpanel" aria-labelledby="signin-tab-email" data-signin-panel="email" @if($openTab !== 'email') hidden @endif>
                    <div class="mb-3">
                        <label for="login" class="form-label fw-semibold">{{ __('Email or username') }}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" name="login" class="form-control" id="login" placeholder="{{ __('you@example.com or username') }}"
                                   value="{{ $openTab === 'email' ? old('login') : '' }}" autocomplete="username" required @disabled($openTab !== 'email')>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold">{{ __('Password') }}</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" id="password" placeholder="{{ __('Your password') }}" autocomplete="current-password" required>
                        <span class="input-group-text bg-transparent cursor-pointer" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center my-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="rememberMe">
                        <label class="form-check-label" for="rememberMe">{{ __('Remember Me') }}</label>
                    </div>
                    <a href="{{ route('user.password.request') }}" class="text-decoration-none text-primary fw-semibold">
                        {{ __('Forgot Password') }}?
                    </a>
                </div>

                @if(config('services.recaptcha.status'))
                    <div class="g-recaptcha mt-3 mb-3" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
                @endif

                <button class="btn btn-primary btn-submit-auth" type="submit">
                    {{ __('Sign In') }} <i class="fa-light fa-arrow-right ms-1"></i>
                </button>
            </form>

            @if(app(\App\Services\Auth\OtpLoginService::class)->isAvailable())
                <div class="psi-divider" aria-hidden="true"><span>{{ __('or') }}</span></div>
                <a href="{{ route('user.login.otp') }}" class="btn btn-outline-secondary w-100 psi-sms-link" data-otp-login-link>
                    <i class="fa-light fa-message-sms me-1"></i>{{ __('Get a one-time code by SMS') }}
                </a>
            @endif

            <p class="text-center mt-4 mb-2">
                {{ __('New to :site?', ['site' => setting('site_title')]) }}
                <a href="{{ route('user.register') }}" class="text-decoration-none text-primary fw-semibold">{{ __('Create an account') }}</a>
            </p>

            <x-demo-credentials portal="user" form-id="userLoginForm" />

            @include('frontend.auth.partials._portal_switch', [
                'current' => 'user',
                'page'    => 'login',
                'heading' => __('Switch portal'),
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
