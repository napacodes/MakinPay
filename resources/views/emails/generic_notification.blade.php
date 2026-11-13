{{--
    The transactional email every generic notification is sent in.

    Email-safe on purpose: tables, inline CSS, a 600px column that shrinks
    on a phone, a bulletproof button. Design option 1b from the handoff,
    with the brand read from the site's own settings — this template ships
    to every DigiKash installation, so the logo and name are the buyer's.

    $title and $body are what App\Mail\GenericNotification has always
    passed. The rest is optional and rendered only when given:
      $badge      ['label' => 'Approved', 'tone' => 'success|warning|danger|info']
      $details    ['Agent code' => 'AG-48213', ...]
      $actionText / $actionUrl
      $greeting
--}}
@php
    use Illuminate\Support\Facades\Route;

    $siteTitle  = setting('site_title', config('app.name'));

    /*
     * The brand row is a square mark beside the site's name, so the mark is
     * the small logo — the wide one already spells the name, and next to the
     * text it read twice. An install without a small logo gets the shipped
     * mark rather than nothing.
     */
    $siteMark   = setting('small_logo');
    $logoUrl    = $siteMark ? asset($siteMark) : asset('general/static/email/digikash-logo-mark.png');
    $shieldUrl  = asset('general/static/email/shield-check.png');
    $badge      = $badge ?? null;
    $details    = $details ?? [];
    $actionText = $actionText ?? null;
    $actionUrl  = $actionUrl ?? null;
    $greeting   = $greeting ?? null;

    $tones = [
        'success' => ['text' => '#15803d', 'bg' => '#ecfdf3', 'border' => '#bbf7d0', 'dot' => '#16a34a'],
        'warning' => ['text' => '#b45309', 'bg' => '#fffbeb', 'border' => '#fde68a', 'dot' => '#f59e0b'],
        'danger'  => ['text' => '#b91c1c', 'bg' => '#fef2f2', 'border' => '#fecaca', 'dot' => '#ef4444'],
        'info'    => ['text' => '#1d4ed8', 'bg' => '#eff6ff', 'border' => '#bfdbfe', 'dot' => '#2f6ef3'],
    ];
    $tone = $tones[$badge['tone'] ?? 'info'] ?? $tones['info'];

    $font = "'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif";

    /*
     * Footer links resolve against the routes this installation really has.
     * A link that 404s from an email is worse than no link, so each one is
     * dropped rather than guessed.
     */
    $firstRoute = function (array $names, array $parameters = []): ?string {
        foreach ($names as $name) {
            if (Route::has($name)) {
                return route($name, $parameters);
            }
        }

        return null;
    };

    $footerLinks = array_filter([
        __('Help center')           => $firstRoute(['user.support-ticket.index', 'user.ticket.index']),
        __('Privacy')               => $firstRoute(['page.view'], ['slug' => 'privacy']),
        __('Notification settings') => $firstRoute(['user.settings.profile', 'user.profile']),
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ language_direction() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $title }}</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { margin:0; padding:0; background:#f3f5f9; -webkit-text-size-adjust:100%; }
    table { border-collapse:collapse; }
    img { border:0; display:block; }
    a { color:#2f6ef3; text-decoration:none; }
    @media (max-width: 640px) {
        .wrap { width:100% !important; }
        .pad  { padding-left:24px !important; padding-right:24px !important; }
        .h1   { font-size:24px !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#f3f5f9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f9;">
<tr><td align="center" style="padding:40px 12px;">
<table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;">

    {{-- Brand row --}}
    <tr><td style="padding:0 8px 20px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td style="font-family:{{ $font }};font-size:22px;font-weight:800;letter-spacing:-0.4px;color:#0f172a;">
                {{-- On the brand tint, so a light mark — many buyers' small
                     logos are drawn for a dark header — still reads here. --}}
                <img src="{{ $logoUrl }}" width="30" height="30" alt="{{ $siteTitle }}" style="display:inline-block;width:30px;height:30px;vertical-align:middle;margin-right:10px;border-radius:8px;background:#eaf1ff;">
                <span style="vertical-align:middle;">{{ $siteTitle }}</span>
            </td>
            <td align="right" style="font-family:{{ $font }};font-size:12px;font-weight:500;color:#64748b;">{{ now()->format('D, d M Y') }}</td>
        </tr></table>
    </td></tr>

    {{-- Card --}}
    <tr><td style="background:#ffffff;border:1px solid #e3e8f0;border-radius:20px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04),0 12px 32px -12px rgba(47,110,243,.18);">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            {{-- Outlook draws the solid bgcolor; everything else the gradient. --}}
            <tr><td height="5" bgcolor="#2f6ef3" style="height:5px;line-height:5px;font-size:0;background:#2f6ef3;background-image:linear-gradient(90deg,#2f6ef3,#56a9ff);border-radius:20px 20px 0 0;">&nbsp;</td></tr>
            <tr><td class="pad" style="padding:40px 44px 36px;font-family:{{ $font }};">

                @if($badge && ! empty($badge['label']))
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:22px;"><tr>
                    <td style="background:{{ $tone['bg'] }};border:1px solid {{ $tone['border'] }};border-radius:999px;padding:6px 10px;font-family:{{ $font }};font-size:12px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{ $tone['text'] }};">
                        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:{{ $tone['dot'] }};margin-right:6px;vertical-align:middle;"></span><span style="vertical-align:middle;">{{ $badge['label'] }}</span>
                    </td>
                </tr></table>
                @endif

                <h1 class="h1" style="margin:0 0 22px;font-family:{{ $font }};font-size:28px;line-height:1.2;font-weight:800;letter-spacing:-0.6px;color:#0f172a;">{{ $title }}</h1>

                <p style="margin:0 0 22px;font-family:{{ $font }};font-size:16px;line-height:1.7;color:#475569;">
                    @if($greeting)<span>{{ $greeting }}</span> @endif{!! nl2br(e($body)) !!}
                </p>

                @if(count($details))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e8edf4;border-radius:14px;margin-bottom:22px;">
                    <tr><td style="padding:6px 20px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        @foreach($details as $label => $value)
                            <tr>
                                <td style="padding:14px 0;font-family:{{ $font }};font-size:13px;font-weight:500;color:#64748b;{{ $loop->last ? '' : 'border-bottom:1px solid #e8edf4;' }}">{{ $label }}</td>
                                <td align="right" style="padding:14px 0;font-family:{{ $font }};font-size:14px;font-weight:600;color:#0f172a;{{ $loop->last ? '' : 'border-bottom:1px solid #e8edf4;' }}">{{ $value }}</td>
                            </tr>
                        @endforeach
                        </table>
                    </td></tr>
                </table>
                @endif

                @if($actionUrl && $actionText)
                <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                    <td bgcolor="#2f6ef3" style="background:#2f6ef3;border-radius:12px;box-shadow:0 8px 20px -8px rgba(47,110,243,.6);">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:15px 28px;font-family:{{ $font }};font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:12px;">{{ $actionText }}</a>
                    </td>
                </tr></table>
                <p style="margin:12px 0 0;font-family:{{ $font }};font-size:13px;line-height:1.6;color:#94a3b8;">{{ __('Button not working? Copy this link:') }} <a href="{{ $actionUrl }}" style="color:#2f6ef3;font-weight:500;word-break:break-all;">{{ $actionUrl }}</a></p>
                @endif

            </td></tr>

            {{-- Security strip --}}
            <tr><td class="pad" style="background:#fbfcfe;border-top:1px solid #eef2f7;padding:20px 44px;border-radius:0 0 20px 20px;">
                <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                    <td valign="top" width="32" style="width:32px;height:32px;background:#eaf1ff;border-radius:10px;" align="center"><img src="{{ $shieldUrl }}" width="16" height="16" alt="" style="margin:8px auto;"></td>
                    <td width="12"></td>
                    <td style="font-family:{{ $font }};font-size:13px;line-height:1.6;color:#64748b;"><strong style="color:#334155;font-weight:600;">{{ __('Security note.') }}</strong> {{ __(':site will never ask for your PIN or OTP by email. If you didn\'t expect this message, you can safely ignore it.', ['site' => $siteTitle]) }}</td>
                </tr></table>
            </td></tr>
        </table>
    </td></tr>

    {{-- Footer --}}
    <tr><td align="center" style="padding:28px 24px 0;font-family:{{ $font }};">
        @if(count($footerLinks))
        <p style="margin:0 0 12px;font-size:13px;font-weight:500;">
            @foreach($footerLinks as $label => $href)
            <a href="{{ $href }}" style="color:#475569;margin:0 10px;">{{ $label }}</a>
            @endforeach
        </p>
        @endif
        <p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">{{ __('This is an automated message from') }} {{ $siteTitle }}.<br>&copy; {{ date('Y') }} {{ $siteTitle }}. {{ __('All rights reserved') }}.</p>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
