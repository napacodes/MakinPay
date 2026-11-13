@extends('frontend.layouts.auth')
@section('title', __('Verify Your Email'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/agent.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/agent.css'))) }}">
@endpush
@section('auth-content')
    @php
        $user = auth()->user();
        $emailMasked = $user?->email
            ? preg_replace_callback('/^(.).*(.)@/u', fn ($m) => $m[1].'***'.$m[2].'@', (string) $user->email)
            : '';
    @endphp
    <div class="auth-shell">
        <div class="auth-card is-user">

            <div class="auth-head">
                <img src="{{ asset(setting('logo')) }}" alt="Logo" class="auth-logo" loading="lazy">
                <h4 class="auth-title">{{ __('Check your inbox') }}</h4>
                <p class="auth-subtitle">
                    {{ __('We sent a verification link to') }}
                    @if($emailMasked)
                        <strong>{{ $emailMasked }}</strong>.
                    @else
                        {{ __('your email address') }}.
                    @endif
                    {{ __('Open it to activate your account.') }}
                </p>
                <span class="auth-role-badge is-user">
                    <i class="fa-duotone fa-envelope-open-text"></i> {{ __('Email Verification') }}
                </span>
            </div>

            @if(session('status') === 'verification-link-sent')
                <div class="alert alert-success d-flex align-items-start gap-2" role="status">
                    <i class="fa-solid fa-circle-check mt-1"></i>
                    <span>{{ __('A new verification link has been sent to your email address.') }}</span>
                </div>
            @endif

            <ol class="ps-3 small text-muted mb-4">
                <li class="mb-2">{{ __('Open the email from :site_title.', ['site_title' => setting('site_title')]) }}</li>
                <li class="mb-2">{{ __('Click the verification link. It works on any device or browser, not only this one.') }}</li>
                <li>{{ __("Didn't get it? Check your spam folder, or resend it below.") }}</li>
            </ol>

            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-submit-auth">
                    <i class="fa-light fa-paper-plane me-1"></i> {{ __('Resend Verification Email') }}
                </button>
            </form>

            <div class="text-center mt-4">
                <span class="text-muted">{{ __('Wrong email address?') }}</span>
                <form method="POST" action="{{ route('user.logout') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-link p-0 align-baseline text-decoration-none text-primary fw-semibold">
                        {{ __('Sign out and register again') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
