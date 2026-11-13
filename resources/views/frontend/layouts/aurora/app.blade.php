@php
	// The inline script in _head swaps this for the visitor's saved choice (or
	// their device setting) before first paint; the attribute itself is what a
	// no-JavaScript visitor gets. "system" cannot be resolved on the server.
	$auroraMode = \App\Enums\Theme::Aurora->defaultMode() === 'light' ? 'light' : 'dark';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ language_direction() }}" data-theme="aurora" data-mode="{{ $auroraMode }}">
@include('frontend.layouts.aurora.partials._head')
<body class="au-body">

	{{-- Demo Mode Banner (renders only when APP_DEMO=true) --}}
	<x-demo-banner />

	@include('frontend.layouts.aurora.partials._header')

	<main id="au-main">
		@yield('content')
	</main>

	{{-- Cookies Include --}}
	@if(setting('cookie_status'))
		@include('frontend.layouts.partials._cookies')
	@endif

	@include('frontend.layouts.aurora.partials._footer')
	@include('frontend.layouts.aurora.partials._script')
</body>
</html>
