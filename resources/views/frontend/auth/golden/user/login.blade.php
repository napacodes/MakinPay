@extends('frontend.layouts.golden.auth')
@section('title', __('User Login'))

@section('auth-content')
	<section class="shell">
		<div class="form-panel">
			@include('frontend.layouts.golden.partials._auth_form_top')

			<div class="form-body">
				@if(session('status'))
					<div class="flash-status">
						<span class="flash-status__icon"><i class="fa-solid fa-check"></i></span>
						<span>{{ session('status') }}</span>
					</div>
				@endif

				@if($errors->any())
					<div class="alert alert--validation is-visible" role="alert">
						<span class="alert__icon"><i class="fa-solid fa-exclamation"></i></span>
						<span class="alert__msg">
							<ul class="alert__list">
								@foreach($errors->all() as $error)
									<li>{{ $error }}</li>
								@endforeach
							</ul>
						</span>
						<button type="button" class="alert__close" data-alert-close aria-label="{{ __('Dismiss') }}"><i class="fa-solid fa-xmark"></i></button>
					</div>
				@endif

				<div class="eyebrow form-eyebrow">{{ __('Welcome Back') }}</div>
				<h1 class="form-title">
					{!! __('Resume your :em session.', ['em' => '<em class="italic-gold">'.__('private').'</em>']) !!}
				</h1>
				<p class="form-desc">{{ __('Two factors required for accounts with active yield programmes.') }}</p>

				@php
					// The admin's choice of tab, unless the last submission came from the other one.
					$openTab = old('login') !== null
						? (old('login_country') !== null ? 'phone' : 'email')
						: \App\Enums\SignInField::fromSetting()->value;
				@endphp

				<form action="{{ route('user.login') }}" method="POST" novalidate>
					@csrf

					<div class="psi-tabs psi-tabs--golden" role="tablist" aria-label="{{ __('Sign in with') }}" data-signin-tabs>
						<button type="button" class="psi-tabs__tab {{ $openTab === 'phone' ? 'is-active' : '' }}" role="tab"
						        id="gdk-tab-phone" aria-controls="gdk-panel-phone"
						        aria-selected="{{ $openTab === 'phone' ? 'true' : 'false' }}" tabindex="{{ $openTab === 'phone' ? '0' : '-1' }}"
						        data-signin-tab="phone">
							<i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>{{ __('Phone number') }}
						</button>
						<button type="button" class="psi-tabs__tab {{ $openTab === 'email' ? 'is-active' : '' }}" role="tab"
						        id="gdk-tab-email" aria-controls="gdk-panel-email"
						        aria-selected="{{ $openTab === 'email' ? 'true' : 'false' }}" tabindex="{{ $openTab === 'email' ? '0' : '-1' }}"
						        data-signin-tab="email">
							<i class="fa-regular fa-envelope" aria-hidden="true"></i>{{ __('Email or username') }}
						</button>
					</div>

					<div id="gdk-panel-phone" role="tabpanel" aria-labelledby="gdk-tab-phone" data-signin-panel="phone" @if($openTab !== 'phone') hidden @endif>
						<x-auth.phone-field id="gdk-login-phone" golden :value="$openTab === 'phone' ? old('login') : null" :disabled="$openTab !== 'phone'" />
					</div>

					<div id="gdk-panel-email" role="tabpanel" aria-labelledby="gdk-tab-email" data-signin-panel="email" @if($openTab !== 'email') hidden @endif>
						<div class="field">
							<label for="gdk-login">{{ __('Email or Username') }}</label>
							<div class="field__wrap has-lead">
								<i class="lead fa-regular fa-envelope"></i>
								<input id="gdk-login" name="login" type="text" placeholder="adrien@whitlock.holdings" value="{{ $openTab === 'email' ? old('login') : '' }}" required @disabled($openTab !== 'email')>
							</div>
						</div>
					</div>

					<div class="field">
						<label for="gdk-password">{{ __('Password') }}</label>
						<div class="field__wrap has-lead">
							<i class="lead fa-solid fa-lock"></i>
							<input id="gdk-password" name="password" type="password" placeholder="••••••••••" required autocomplete="current-password">
							<button type="button" class="field__btn" data-eye="gdk-password" aria-label="{{ __('Show password') }}">
								<i class="fa-regular fa-eye"></i>
							</button>
						</div>
					</div>

					<div class="row">
						<label class="check">
							<input type="checkbox" name="remember">
							<span class="check__box"></span>
							<span class="check__lbl">{{ __('Remember this device') }}</span>
						</label>
						<a href="{{ route('user.password.request') }}" class="gold-link gold-link--caps">{{ __('Forgot password?') }}</a>
					</div>

					@if(config('services.recaptcha.status'))
						<div class="field">
							<div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
						</div>
					@endif

					<button type="submit" class="btn btn--filled btn--full">
						{{ __('Sign In') }} <i class="fa-solid fa-arrow-right"></i>
					</button>

					<div class="or-div">{{ __('Or') }}</div>

					@if(app(\App\Services\Auth\OtpLoginService::class)->isAvailable())
						<div class="muted-note--centered" data-otp-login-link>
							<a href="{{ route('user.login.otp') }}" class="gold-link">{{ __('Sign in with an SMS code instead') }}</a>
						</div>
					@endif

					<div class="muted-note--centered">
						{{ __("Don't have an account?") }}
						<a href="{{ route('user.register') }}" class="gold-link">{{ __('Open a Private Wallet') }}</a>
					</div>

					@include('frontend.layouts.golden.partials._auth_portal_switch', ['current' => 'user', 'page' => 'login'])
				</form>
			</div>
		</div>

		@include('frontend.layouts.golden.partials._auth_brand_panel')
	</section>
@endsection

@push('styles')
	<link rel="stylesheet" href="{{ asset('frontend/css/phone-sign-in.css?v=' . config('app.version') . '-' . filemtime(public_path('frontend/css/phone-sign-in.css'))) }}">
@endpush

@push('scripts')
	<script src="{{ asset('frontend/js/phone-sign-in.js?v=' . config('app.version') . '-' . filemtime(public_path('frontend/js/phone-sign-in.js'))) }}"></script>
	@if(config('services.recaptcha.status'))
		<script async src="https://www.google.com/recaptcha/api.js"></script>
	@endif
@endpush
