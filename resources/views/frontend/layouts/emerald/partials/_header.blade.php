<header class="dk-header" data-dk-header>
	<div class="dk-container dk-header__inner">
		<a href="{{ route('home') }}" class="dk-header__brand">{{ setting('site_title') }}</a>

		<nav class="dk-header__nav" aria-label="{{ __('Primary') }}">
			@if(isset($navigations) && $navigations->isNotEmpty())
				@foreach($navigations as $navigation)
					<a href="{{ $navigation->url }}" target="{{ $navigation->target }}">{{ $navigation->label }}</a>
				@endforeach
			@else
				<a href="{{ route('home') }}#features">{{ __('Features') }}</a>
				<a href="{{ route('home') }}#platform">{{ __('Platform') }}</a>
				<a href="{{ route('home') }}#pricing">{{ __('Pricing') }}</a>
				<a href="{{ route('home') }}#faq">{{ __('FAQ') }}</a>
			@endif
		</nav>

		<div class="dk-header__actions">
			<a href="{{ route('user.login') }}" class="dk-btn dk-btn--ghost">{{ __('Log in') }}</a>
			<a href="{{ route('user.register') }}" class="dk-btn dk-btn--primary">{{ __('Create account') }}</a>
		</div>

		<button type="button"
		        class="dk-header__toggle"
		        data-dk-drawer-open
		        aria-expanded="false"
		        aria-controls="dk-drawer">
			<i class="fa-solid fa-bars" aria-hidden="true"></i>
			<span class="dk-visually-hidden">{{ __('Open menu') }}</span>
		</button>
	</div>
</header>

<div class="dk-drawer" id="dk-drawer" data-dk-drawer hidden>
	<div class="dk-header__inner">
		<span class="dk-header__brand">{{ setting('site_title') }}</span>
		<button type="button" class="dk-header__toggle" data-dk-drawer-close>
			<i class="fa-solid fa-xmark" aria-hidden="true"></i>
			<span class="dk-visually-hidden">{{ __('Close menu') }}</span>
		</button>
	</div>

	<nav aria-label="{{ __('Mobile') }}">
		@if(isset($navigations) && $navigations->isNotEmpty())
			@foreach($navigations as $navigation)
				<a class="dk-drawer__link" style="--i: {{ $loop->index }}" href="{{ $navigation->url }}" target="{{ $navigation->target }}">{{ $navigation->label }}</a>
			@endforeach
		@else
			<a class="dk-drawer__link" style="--i: 0" href="{{ route('home') }}#features">{{ __('Features') }}</a>
			<a class="dk-drawer__link" style="--i: 1" href="{{ route('home') }}#platform">{{ __('Platform') }}</a>
			<a class="dk-drawer__link" style="--i: 2" href="{{ route('home') }}#pricing">{{ __('Pricing') }}</a>
			<a class="dk-drawer__link" style="--i: 3" href="{{ route('home') }}#faq">{{ __('FAQ') }}</a>
		@endif
	</nav>

	<div class="dk-header__actions" style="display:flex;margin-top:auto">
		<a href="{{ route('user.login') }}" class="dk-btn dk-btn--ghost">{{ __('Log in') }}</a>
		<a href="{{ route('user.register') }}" class="dk-btn dk-btn--primary">{{ __('Create account') }}</a>
	</div>
</div>
