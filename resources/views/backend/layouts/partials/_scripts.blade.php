{{-- =========================
| All JavaScript Files Link
|========================= --}}

{{-- Core Libraries --}}
<script src="{{ asset('general/js/jquery.min.js') }}"></script>
<script src="{{ asset('general/js/jquery-migrate.js') }}"></script>
<script src="{{ asset('general/js/jquery-ui.min.js') }}"></script>

{{-- Plugins --}}
<script src="{{ asset('backend/js/summernote-lite.min.js') }}"></script>
<script src="{{ asset('backend/js/tagify.min.js') }}"></script>
<script src="{{ asset('general/js/chartjs.js') }}"></script>
<script src="{{ asset('general/js/apexcharts.min.js') }}" defer></script>
<script src="{{ asset('general/js/moment.js') }}"></script>
<script src="{{ asset('general/js/daterangepicker.min.js') }}"></script>
<script src="{{ asset('general/js/datepicker.min.js') }}"></script>
<script src="{{ asset('general/js/clipboard.min.js') }}"></script>
<script src="{{ asset('backend/js/sortable.js') }}"></script>

{{-- Notifications & Helpers --}}
<script src="{{ asset('general/js/simple-notify.min.js') }}"></script>
@php($helpersJsVersion = config('app.version').'-'.filemtime(public_path('general/js/helpers.js')))
<script src="{{ asset('general/js/helpers.js?v='.$helpersJsVersion) }}"></script>

{{-- Shared money maths. The customer side has had this since the formatter was
     unified; the admin side was left hand-rolling toFixed(2), which is the one
     place a crypto figure still rendered as "0.00". Plain global script, no
     dependencies, same file both panels load. --}}
@php($moneyJsVersion = config('app.version').'-'.filemtime(public_path('frontend/js/digikash-money.js')))
<script src="{{ asset('frontend/js/digikash-money.js?v='.$moneyJsVersion) }}"></script>

{{-- Backend Main Scripts --}}
<script src="{{ asset('backend/js/app.js') }}"></script>
<script src="{{ asset('backend/js/main.js?v=' . config('app.version') . '-' . filemtime(public_path('backend/js/main.js'))) }}"></script>

{{-- Admin shell (sidebar + header) --}}
@php($adminShellJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/admin-shell.js')))
<script src="{{ asset('backend/js/admin-shell.js?v='.$adminShellJsVersion) }}"></script>

{{-- Header: the command palette with its shortcuts, and the notification panel --}}
@php($adminPaletteJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/admin-palette.js')))
<script src="{{ asset('backend/js/admin-palette.js?v='.$adminPaletteJsVersion) }}"></script>
@php($adminNotificationsJsVersion = config('app.version').'-'.filemtime(public_path('backend/js/admin-notifications.js')))
<script src="{{ asset('backend/js/admin-notifications.js?v='.$adminNotificationsJsVersion) }}"></script>

{{-- Real-Time Notifications --}}
@include('general.notification_config._tune_player', ['notificationArea' => 'admin'])
@include('general.notification_config._realtime_config', ['notificationArea' => 'admin'])
@include('general.notification_config._poller', ['notificationArea' => 'admin'])

{{-- Backend  Helpers --}}
@include('backend.partials._helper_js')


{{-- Global Date Range Picker & Notify Configuration --}}
@include('general._notify_evs')
@include('general._date_range_picker')

{{-- Page Specific Scripts --}}
@yield('scripts')
@stack('scripts')
