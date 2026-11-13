@extends('frontend.layouts.golden.auth')
@section('title', __('Sign in with an SMS code'))

@section('auth-content')
	<section class="shell">
		<div class="form-panel">
			@include('frontend.layouts.golden.partials._auth_form_top', [
				'backLabel' => __('Back to Sign In'),
				'backUrl'   => route('user.login'),
			])

			<div class="form-body">
				@if(session('otp_sent'))
					<div class="flash-status" role="status" data-otp-sent>
						<span class="flash-status__icon"><i class="fa-solid fa-check"></i></span>
						<span>{{ session('otp_sent') }}</span>
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

				<div class="eyebrow form-eyebrow">{{ __('User Account') }}</div>
				<h1 class="form-title">{{ __('Sign in with an SMS code') }}</h1>
				<p class="form-desc">
					{{ $login === null
						? __('Enter the phone number on your account and we will text you a one-time code.')
						: __('Enter the code we texted to :number.', ['number' => \App\Support\MaskedPhone::forDisplay($login)]) }}
				</p>

				@if($login === null)
					<form action="{{ route('user.login.otp.send') }}" method="POST" novalidate data-otp-step="number">
						@csrf
						<x-auth.phone-field id="gdk-otp-login" golden :value="old('login')" autofocus />

						@if(config('services.recaptcha.status'))
							<div class="field">
								<div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
							</div>
						@endif

						<button type="submit" class="btn btn--filled btn--full">
							{{ __('Send me a code') }} <i class="fa-solid fa-arrow-right"></i>
						</button>
					</form>
				@else
					<form action="{{ route('user.login.otp.verify') }}" method="POST" novalidate data-otp-step="code">
						@csrf
						<input type="hidden" name="login" value="{{ $login }}">
						<div class="field psf-code psf-code--golden {{ $errors->has('code') ? 'has-error' : '' }}" data-otp-boxes data-otp-length="6" data-otp-digit-label="{{ __('Digit :n') }}">
							<label for="gdk-otp-code">{{ __('Sign-in code') }}</label>
							<div class="field__wrap has-lead psf-code__fallback">
								<i class="lead fa-solid fa-key"></i>
								<input id="gdk-otp-code" name="code" type="text" inputmode="numeric" maxlength="10" placeholder="{{ __('6-digit code') }}" required autocomplete="one-time-code" autofocus>
							</div>
						</div>

						<div class="psf-code psf-code--golden">
							<div class="psf-code__meta" data-otp-countdown="60">
								<span data-otp-waiting>{{ __('Resend code in') }} <strong data-otp-time>1:00</strong></span>
								<span data-otp-ready hidden>{{ __("Didn't get it? Use the button below.") }}</span>
							</div>
						</div>

						@if(config('services.recaptcha.status'))
							<div class="field">
								<div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
							</div>
						@endif

						<button type="submit" class="btn btn--filled btn--full">
							{{ __('Sign In') }} <i class="fa-solid fa-arrow-right"></i>
						</button>
					</form>

					<form action="{{ route('user.login.otp.restart') }}" method="POST" class="u-mt-14">
						@csrf
						<button type="submit" class="btn btn--ghost btn--full">{{ __('Send a new code, or use another number') }}</button>
					</form>
				@endif

				<div class="form-footer">
					<a href="{{ route('user.login') }}" class="gold-link">{{ __('Sign in with your password instead') }}</a>
				</div>
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
