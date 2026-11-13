@php
    $pwa = app(\App\Http\Controllers\Frontend\PwaController::class);
@endphp
@if($pwa->isPwaEnabled() && $pwa->isPreloaderEnabled())
    @php
        $preloaderBackground = $pwa->backgroundColor();
        $preloaderTheme      = $pwa->themeColor();
        $preloaderIcon       = $pwa->iconUrl('icon_192');
        $preloaderName       = $pwa->appName();
        [$bgRed, $bgGreen, $bgBlue] = sscanf($preloaderBackground, '#%02x%02x%02x');
        $preloaderTextColor = (($bgRed * 299) + ($bgGreen * 587) + ($bgBlue * 114)) / 1000 >= 128 ? '#1f2937' : '#ffffff';
        [$themeRed, $themeGreen, $themeBlue] = sscanf($preloaderTheme, '#%02x%02x%02x');
        $preloaderTrackColor = "rgba({$themeRed}, {$themeGreen}, {$themeBlue}, 0.25)";
    @endphp
    <div id="dk-pwa-preloader" hidden aria-hidden="true">
        <style>
            #dk-pwa-preloader {
                position: fixed;
                inset: 0;
                z-index: 2147483000;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 18px;
                background: {{ $preloaderBackground }};
                color: {{ $preloaderTextColor }};
                font-family: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
                text-align: center;
                opacity: 1;
                transition: opacity 0.4s ease;
            }
            #dk-pwa-preloader[hidden] { display: none; }
            #dk-pwa-preloader.dk-pwa-preloader--done { opacity: 0; pointer-events: none; }
            #dk-pwa-preloader .dk-pwa-preloader__icon {
                width: 88px;
                height: 88px;
                border-radius: 22px;
                object-fit: cover;
                box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
                animation: dk-pwa-preloader-pulse 1.6s ease-in-out infinite;
            }
            #dk-pwa-preloader .dk-pwa-preloader__spinner {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                border: 3px solid {{ $preloaderTrackColor }};
                border-top-color: {{ $preloaderTheme }};
                animation: dk-pwa-preloader-spin 0.9s linear infinite;
            }
            #dk-pwa-preloader .dk-pwa-preloader__name {
                font-size: 18px;
                font-weight: 700;
                letter-spacing: 0.01em;
            }
            @keyframes dk-pwa-preloader-spin { to { transform: rotate(360deg); } }
            @keyframes dk-pwa-preloader-pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }
            @media (prefers-reduced-motion: reduce) {
                #dk-pwa-preloader .dk-pwa-preloader__icon { animation: none; }
            }
        </style>
        <img class="dk-pwa-preloader__icon" src="{{ $preloaderIcon }}" alt="" width="88" height="88">
        <div class="dk-pwa-preloader__spinner" role="status" aria-label="{{ __('Loading') }}"></div>
        <div class="dk-pwa-preloader__name">{{ $preloaderName }}</div>
    </div>
    <script>
        'use strict';
        (function () {
            var el = document.getElementById('dk-pwa-preloader');
            if (!el) { return; }

            var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                || window.matchMedia('(display-mode: fullscreen)').matches
                || window.matchMedia('(display-mode: minimal-ui)').matches
                || window.navigator.standalone === true
                || document.referrer.indexOf('android-app://') === 0;

            var alreadyShown = false;
            try { alreadyShown = sessionStorage.getItem('dk_pwa_splash_done') === '1'; } catch (e) {}

            if (!isStandalone || alreadyShown) {
                el.parentNode.removeChild(el);
                return;
            }

            try { sessionStorage.setItem('dk_pwa_splash_done', '1'); } catch (e) {}

            el.hidden = false;

            var startedAt = Date.now();
            var MIN_VISIBLE_MS = 700;
            var MAX_VISIBLE_MS = 5000;
            var FADE_MS = 400;
            var finished = false;

            function hidePreloader() {
                if (finished) { return; }
                finished = true;
                var remaining = Math.max(0, MIN_VISIBLE_MS - (Date.now() - startedAt));
                setTimeout(function () {
                    el.classList.add('dk-pwa-preloader--done');
                    setTimeout(function () {
                        if (el.parentNode) { el.parentNode.removeChild(el); }
                    }, FADE_MS);
                }, remaining);
            }

            if (document.readyState === 'complete') {
                hidePreloader();
            } else {
                window.addEventListener('load', hidePreloader);
            }
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) { hidePreloader(); }
            });
            setTimeout(hidePreloader, MAX_VISIBLE_MS);
        })();
    </script>
@endif
