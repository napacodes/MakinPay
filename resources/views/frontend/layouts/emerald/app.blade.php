<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ language_direction() }}" data-theme="emerald">
@include('frontend.layouts.emerald.partials._head')
<body class="dk-emerald">

	{{-- Demo Mode Banner (renders only when APP_DEMO=true) --}}
	<x-demo-banner />

	@include('frontend.layouts.emerald.partials._header')

	<main id="dk-main">
		@yield('content')
	</main>

	{{-- Cookies Include --}}
	@if(setting('cookie_status'))
		@include('frontend.layouts.partials._cookies')
	@endif

	@include('frontend.layouts.emerald.partials._footer')
	@include('frontend.layouts.emerald.partials._script')
</body>
</html>
