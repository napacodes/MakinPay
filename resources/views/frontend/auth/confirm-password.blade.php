@extends('frontend.layouts.auth')
@section('title', __('Confirm Password'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('frontend/css/agent.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/agent.css'))) }}">
@endpush
@section('auth-content')
    <div class="auth-shell">
        <div class="auth-card is-user">

            <div class="auth-head">
                <img src="{{ asset(setting('logo')) }}" alt="Logo" class="auth-logo" loading="lazy">
                <h4 class="auth-title">{{ __('Confirm your password') }}</h4>
                <p class="auth-subtitle">{{ __('This is a secure area of your account. Enter your password to continue.') }}</p>
                <span class="auth-role-badge is-user">
                    <i class="fa-duotone fa-shield-check"></i> {{ __('Security Check') }}
                </span>
            </div>

            @if ($errors->any())
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    @foreach ($errors->all() as $error)
                        <strong>{{ $error }}</strong><br>
                    @endforeach
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <form method="POST" action="{{ route('password.confirm') }}">
                @csrf
                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold">{{ __('Password') }}</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="{{ __('Enter your password') }}" required autocomplete="current-password" autofocus>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-submit-auth">
                    <i class="fa-light fa-check me-1"></i> {{ __('Confirm') }}
                </button>
            </form>

            <p class="text-center mt-4 mb-0">
                <a href="{{ route('user.password.request') }}" class="text-decoration-none text-primary fw-semibold">
                    {{ __('Forgot your password?') }}
                </a>
            </p>
        </div>
    </div>
@endsection
