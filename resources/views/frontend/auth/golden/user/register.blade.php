@extends('frontend.layouts.golden.auth')
@section('title', __('Create User Account'))

@section('auth-content')
	@php
		$myCurrentLocation = registrationLocation();
		$allCountries      = getCountries();
		$selectedCountry   = strtoupper((string) old('country', $myCurrentLocation['country_code'] ?? ''));
		$home              = \App\Support\HomeCountry::country();
		$livesAway         = $home !== null && $selectedCountry !== '' && $selectedCountry !== $home['code'];
		$dialCode          = getCountryByCode($selectedCountry)['dial_code'] ?? ($myCurrentLocation['dial_code'] ?? '');
	@endphp

	<section class="shell form-register">
		<div class="form-panel">
			@include('frontend.layouts.golden.partials._auth_form_top')

			<div class="form-body">
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

				<div class="eyebrow form-eyebrow">{{ __('Open an Account') }}</div>
				<h1 class="form-title">
					{!! __('Begin a :em relationship.', ['em' => '<em class="italic-gold">'.__('considered').'</em>']) !!}
				</h1>
				<p class="form-desc">{{ __('Your application is reviewed personally — never algorithmically.') }}</p>

				<form action="{{ route('user.register') }}" method="POST" novalidate>
					@csrf

					<div class="form-section">{{ __('Where do you live?') }}</div>
					@if($home !== null)
						<div class="psi-residence psi-residence--golden" role="radiogroup" aria-label="{{ __('Where do you live?') }}" data-residence data-home="{{ $home['code'] }}">
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
					<div class="grid-2">
						<div class="field" data-residence-picker>
							<label for="gdk-country">{{ __('Country of residence') }}</label>
							<div class="select-wrap">
								<span class="select-wrap__flag" aria-hidden="true"></span>
								<select id="gdk-country" name="country" data-residence-select required>
									<option value="" disabled @selected(! $selectedCountry)>{{ __('Select Country') }}</option>
									@foreach($allCountries as $country)
										@php $code = strtoupper((string) $country['code']); @endphp
										<option value="{{ $code }}"
										        data-dial-code="{{ $country['dial_code'] ?? '' }}"
										        @selected($selectedCountry === $code)>
											{{ getCountryDisplayLabel($code) ?? title((string) $country['name']) }}
										</option>
									@endforeach
								</select>
								<i class="select-wrap__caret fa-solid fa-chevron-down"></i>
							</div>
						</div>
						<div class="field">
							<label for="gdk-phone">{{ __('Phone Number') }} · {{ __('Used to sign in') }}</label>
							<div class="dial">
								<span class="dial__code">{{ $dialCode }} <i class="fa-solid fa-chevron-down"></i></span>
								<input id="gdk-phone" name="phone" type="tel" placeholder="{{ __('Phone') }}" value="{{ old('phone') }}" autocomplete="tel-national" inputmode="tel">
							</div>
						</div>
					</div>

					<div class="form-section">{{ __('Personal Details') }}</div>
					<div class="grid-2">
						<div class="field">
							<label for="gdk-first">{{ __('First Name') }}</label>
							<div class="field__wrap">
								<input id="gdk-first" name="first_name" type="text" placeholder="Adrien" value="{{ old('first_name') }}" required>
							</div>
						</div>
						<div class="field">
							<label for="gdk-last">{{ __('Last Name') }}</label>
							<div class="field__wrap">
								<input id="gdk-last" name="last_name" type="text" placeholder="Whitlock" value="{{ old('last_name') }}" required>
							</div>
						</div>
						<div class="field">
							<label for="gdk-user">{{ __('Username') }}</label>
							<div class="field__wrap has-lead">
								<i class="lead fa-solid fa-at"></i>
								<input id="gdk-user" name="username" type="text" placeholder="a.whitlock" value="{{ old('username') }}" required>
							</div>
						</div>
						<div class="field">
							<label for="gdk-email">{{ __('Email Address') }}</label>
							<div class="field__wrap has-lead">
								<i class="lead fa-regular fa-envelope"></i>
								<input id="gdk-email" name="email" type="email" placeholder="adrien@whitlock.holdings" value="{{ old('email') }}" required>
							</div>
						</div>
					</div>


					<div class="form-section">{{ __('Security') }}</div>
					<div class="grid-2">
						<div class="field">
							<label for="gdk-pass">{{ __('Password') }}</label>
							<div class="field__wrap has-lead">
								<i class="lead fa-solid fa-lock"></i>
								<input id="gdk-pass" name="password" type="password" placeholder="{{ __('At least 12 characters') }}" data-strength required autocomplete="new-password">
								<button type="button" class="field__btn" data-eye="gdk-pass" aria-label="{{ __('Show password') }}">
									<i class="fa-regular fa-eye"></i>
								</button>
							</div>
							<div class="meter" data-meter-for="gdk-pass">
								<span class="meter__pip"></span><span class="meter__pip"></span><span class="meter__pip"></span><span class="meter__pip"></span>
							</div>
							<div class="meter__lbl" data-meter-lbl="gdk-pass">{{ __('Strength · Awaiting input') }}</div>
						</div>
						<div class="field">
							<label for="gdk-pass-confirm">{{ __('Confirm Password') }}</label>
							<div class="field__wrap has-lead">
								<i class="lead fa-solid fa-lock"></i>
								<input id="gdk-pass-confirm" name="password_confirmation" type="password" placeholder="{{ __('Re-enter your passphrase') }}" data-confirm="gdk-pass" required autocomplete="new-password">
								<span class="field__suffix"><i class="fa-solid fa-check" data-match></i></span>
							</div>
							<div class="meter" data-meter-for="gdk-pass-confirm">
								<span class="meter__pip"></span><span class="meter__pip"></span><span class="meter__pip"></span><span class="meter__pip"></span>
							</div>
							<div class="meter__lbl" data-meter-lbl="gdk-pass-confirm">{{ __('Match · Awaiting input') }}</div>
						</div>
					</div>

					@if($home !== null)
						<div class="psi-note psi-note--golden {{ $livesAway ? 'is-away' : '' }}" data-residence-note-wrap>
							<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
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

					<div class="row u-mt-14">
						<label class="check">
							<input type="checkbox" name="terms" required>
							<span class="check__box"></span>
							<span class="check__lbl">
								{!! __('I accept the :terms and :privacy.', [
									'terms'   => '<a href="#" class="gold-link">'.__('Terms').'</a>',
									'privacy' => '<a href="#" class="gold-link">'.__('Privacy Policy').'</a>',
								]) !!}
							</span>
						</label>
					</div>

					@if(config('services.recaptcha.status'))
						<div class="field">
							<div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.key') }}"></div>
						</div>
					@endif

					<button type="submit" class="btn btn--filled btn--full u-mt-8">
						{{ __('Open Account') }} <i class="fa-solid fa-arrow-right"></i>
					</button>

					<div class="form-footer">
						{{ __('Already have an account?') }}
						<a href="{{ route('user.login') }}" class="gold-link">{{ __('Sign In') }}</a>
					</div>

					@include('frontend.layouts.golden.partials._auth_portal_switch', ['current' => 'user', 'page' => 'register'])
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
