{{-- Tawk.to live chat. Renders only when the plugin is enabled and both IDs are
     real values — the admin form pre-fills the literal placeholders "property_id"
     and "widget_id", which must never reach the embed URL. The alphanumeric guard
     also keeps stored credentials from injecting markup into the script URL. --}}
@php
    $tawkCredentials = pluginCredentials('tawk');
    $tawkPropertyId  = trim((string) ($tawkCredentials['property_id'] ?? ''));
    $tawkWidgetId    = trim((string) ($tawkCredentials['widget_id'] ?? ''));
    $tawkEnabled     = ! empty($tawkCredentials['status'])
        && preg_match('/^[a-zA-Z0-9]+$/', $tawkPropertyId)
        && preg_match('/^[a-zA-Z0-9]+$/', $tawkWidgetId);
@endphp
@if($tawkEnabled)
    <script type="text/javascript">
        var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
        (function () {
            var s1 = document.createElement("script"), s0 = document.getElementsByTagName("script")[0];
            s1.async = true;
            s1.src = 'https://embed.tawk.to/{{ $tawkPropertyId }}/{{ $tawkWidgetId }}';
            s1.charset = 'UTF-8';
            s1.setAttribute('crossorigin', '*');
            s0.parentNode.insertBefore(s1, s0);
        })();
    </script>
@endif
