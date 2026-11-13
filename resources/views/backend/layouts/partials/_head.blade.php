<head>
    {{-- Meta Tags --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    
    {{-- Page Title --}}
    <title>{{ setting('site_title') }} | @yield('title')</title>

    @if(config('app.demo'))
        {{-- Demo Mode Disclosure (for automated scanners & reviewers) --}}
        <meta name="x-demo-mode" content="true">
        <meta name="x-demo-disclaimer" content="This is a software product demo. No real financial services, deposits, or cryptocurrency investments are offered. All data shown is fictitious and for evaluation purposes only.">
        <meta name="x-demo-disclosure" content="{{ url('/demo-disclosure') }}">
        <meta name="x-demo-vendor" content="{{ config('app.demo_vendor_name') }}">
        <link rel="alternate" type="text/html" title="Software Demo Disclosure" href="{{ url('/demo-disclosure') }}">
    @endif
    
    {{-- Favicon --}}
    <link rel="shortcut icon" href="{{ asset(setting('site_favicon')) }}" type="image/x-icon"/>
    
    {{-- Core Vendor CSS --}}
    {{-- Busted on file time like the application sheets below it. This one used
         to fetch its eight faces from Google; when it changed to serve them
         from public/general/webfonts, every browser still holding the old copy
         kept asking Google and kept repainting the panel mid-load - which is
         the swap this stylesheet now exists to stop. --}}
    @php($fontCssVersion = config('app.version').'-'.filemtime(public_path('general/css/google-fonts-inter-jetbrains.css')))
    {{--
        The four faces the panel is actually set in, asked for while the HTML
        is still being read.

        They are downloaded either way - every one of them is used - but a face
        named only inside a stylesheet is not discovered until that stylesheet
        has been fetched and parsed, which measured here at ~300ms before the
        first byte of any font was requested. font-display: block draws no text
        at all until its face lands, so that discovery delay is time the
        operator spends looking at a panel with no words in it, and is what
        "the menu takes a while to load" is. Preloading moves the request to
        the first moment the browser could possibly make it.

        crossorigin is required even same-origin: fonts are fetched in CORS
        mode, and without it the preload is discarded and fetched again.
    --}}
    @foreach (['inter-v19-400', 'inter-v19-500', 'inter-v19-600', 'inter-v19-700'] as $face)
        <link rel="preload" as="font" type="font/woff2" crossorigin
              href="{{ asset('general/webfonts/'.$face.'.woff2') }}">
    @endforeach
    <link rel="stylesheet" href="{{ asset('general/css/google-fonts-inter-jetbrains.css?v='.$fontCssVersion) }}">
    <link rel="stylesheet" href="{{ asset('general/css/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('general/css/chartjs.css') }}">
    <link rel="stylesheet" href="{{ asset('general/css/simple-notify.min.css') }}">
    <link rel="stylesheet" href="{{ asset('general/css/daterangepicker.css') }}">
    
    {{-- Plugin CSS --}}
    <link rel="stylesheet" href="{{ asset('backend/css/summernote-lite.min.css') }}">
    <link rel="stylesheet" href="{{ asset('backend/css/tagify.css') }}">
    
    {{-- Application CSS --}}
    @php($commonCssVersion = config('app.version').'-'.filemtime(public_path('general/css/common.css')))
    <link rel="stylesheet" href="{{ asset('general/css/common.css?v='.$commonCssVersion) }}">
    @php($appCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/app.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/app.css?v='.$appCssVersion) }}">
    {{-- Busted on file time like the sheets below it. Versioning this one on
         app.version alone meant a token added here could stay cached while the
         stylesheet that consumes it arrived fresh, leaving `var()` undefined. --}}
    @php($backendVariablesCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/_variables.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/_variables.css?v='.$backendVariablesCssVersion) }}">
    @php($backendCustomCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/custom.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/custom.css?v='.$backendCustomCssVersion) }}"/>
    @php($adminTableCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/admin-table.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/admin-table.css?v='.$adminTableCssVersion) }}"/>
    @php($observabilityCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/observability.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/observability.css?v='.$observabilityCssVersion) }}"/>
    @php($adminShellCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/admin-shell.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/admin-shell.css?v='.$adminShellCssVersion) }}"/>
    {{-- The one page header (<x-page-header>), styled once here rather than per page. --}}
    @php($pageHeaderCssVersion = config('app.version').'-'.filemtime(public_path('backend/css/page-header.css')))
    <link rel="stylesheet" href="{{ asset('backend/css/page-header.css?v='.$pageHeaderCssVersion) }}"/>

    {{--
        Does this browser transition between documents?

        admin-shell.css fades the content in on every page load, and that fade
        is redundant where the browser cross-fades the whole document for us.
        CSS cannot ask the question: `@supports (view-transition-name: none)`
        answers a different one, and answers it wrongly — Firefox 155 supports
        the property for same-document transitions while implementing no
        @view-transition at all. The pagereveal event ships with the
        cross-document behaviour, so it is the honest test.

        Inline and in the head, because the class has to be on the element
        before the first frame; a fetched file would arrive after the fade it
        is meant to suppress had already begun.
    --}}
    <script>if ('onpagereveal' in window) { document.documentElement.classList.add('dk-vt'); }</script>
    <x-role-branding-theme />
    
    {{-- RTL overrides (only emitted for right-to-left locales) --}}
    <x-rtl-styles area="backend" />

    {{-- Page Specific Styles --}}
    @yield('styles')
    @stack('styles')
</head>
