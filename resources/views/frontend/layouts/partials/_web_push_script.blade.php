@php
    $webPushController = app(\App\Http\Controllers\Frontend\PwaController::class);
    $webPushConfigured = filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
@endphp
@if($webPushController->isPwaEnabled() && $webPushConfigured && auth()->check())
    @php($webPushScriptVersion = config('app.version').'-'.filemtime(public_path('frontend/js/web-push.js')))
    <script src="{{ asset('frontend/js/web-push.js?v='.$webPushScriptVersion) }}" defer></script>
@endif
