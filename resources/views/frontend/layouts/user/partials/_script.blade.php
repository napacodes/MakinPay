{{-- =========================
| All JavaScript Files Link
|========================= --}}

{{-- Core Libraries --}}
<script src="{{ asset('general/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('frontend/js/jquery-3.7.1.min.js') }}"></script>

{{-- Essential Plugins --}}
<script src="{{ asset('frontend/js/owl.carousel.min.js') }}"></script>
<script src="{{ asset('general/js/apexcharts.min.js') }}"></script>
<script src="{{ asset('general/js/moment.js') }}"></script>
<script src="{{ asset('general/js/daterangepicker.min.js') }}"></script>
<script src="{{ asset('general/js/datepicker.min.js') }}"></script>
<script src="{{ asset('general/js/clipboard.min.js') }}"></script>

{{-- Notifications & Helpers --}}
<script src="{{ asset('general/js/simple-notify.min.js') }}"></script>
@php($helpersJsVersion = config('app.version').'-'.filemtime(public_path('general/js/helpers.js')))
<script src="{{ asset('general/js/helpers.js?v='.$helpersJsVersion) }}"></script>

{{-- Shared money maths, so the browser preview agrees with the server. --}}
@php($moneyJsVersion = config('app.version').'-'.filemtime(public_path('frontend/js/digikash-money.js')))
<script src="{{ asset('frontend/js/digikash-money.js?v='.$moneyJsVersion) }}"></script>

{{-- Theme Main Script --}}
@php($dashboardMainJsVersion = config('app.version').'-'.filemtime(public_path('frontend/js/dashboard-main.js')))
<script src="{{ asset('frontend/js/dashboard-main.js?v='.$dashboardMainJsVersion) }}"></script>
{{-- Quick-menu editor (the apps panel in the dashboard header) --}}
@php($quickMenuEditorJsVersion = config('app.version').'-'.filemtime(public_path('frontend/js/quick-menu-editor.js')))
<script src="{{ asset('frontend/js/quick-menu-editor.js?v='.$quickMenuEditorJsVersion) }}"></script>

@include('frontend.layouts.partials._pwa_script')
@include('frontend.layouts.partials._web_push_script')

{{-- Real-Time Notifications --}}
@include('general.notification_config._tune_player', ['notificationArea' => 'user'])
@include('general.notification_config._realtime_config', ['notificationArea' => 'user'])
@include('general.notification_config._poller', ['notificationArea' => 'user'])

{{-- Global Date Picker & Notify Config --}}
@include('general._notify_evs')
@include('general._date_range_picker')

{{-- Tawk.to Live Chat (renders only when the plugin is enabled) --}}
@include('frontend.layouts.partials._tawk_chat')

{{-- Page Specific Scripts --}}
@yield('scripts')
@stack('scripts')
{{-- DK Mobile App Shell controller --}}
@php($mobileAppJsVersion = config('app.version').'-'.filemtime(public_path('frontend/js/dashboard-mobile-app.js')))
<script src="{{ asset('frontend/js/dashboard-mobile-app.js?v='.$mobileAppJsVersion) }}" defer></script>
