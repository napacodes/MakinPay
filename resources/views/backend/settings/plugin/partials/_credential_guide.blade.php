@php
    /**
     * Per-plugin "where to get your credentials" guidance. Keyed by the plugin
     * `code` so each integration shows the right provider console link and the
     * exact steps an admin follows to obtain each key.
     */
    $guides = [
        'google-recaptcha' => [
            'provider'   => 'Google reCAPTCHA',
            'intro'      => __('This site uses reCAPTCHA v2 ("I\'m not a robot" checkbox), so you must create v2 keys — v3 keys will not work here.'),
            'link'       => 'https://www.google.com/recaptcha/admin/create',
            'link_label' => __('Open reCAPTCHA Admin Console'),
            'steps'      => [
                __('Sign in to the reCAPTCHA Admin Console with your Google account.'),
                __('Register a new site and choose the type <code>reCAPTCHA v2</code> → <code>"I\'m not a robot" Checkbox</code>.'),
                __('Add your website domain(s) (e.g. <code>yourdomain.com</code>) and accept the terms.'),
                __('Copy the <code>Site Key</code> into <strong>Recaptcha Key</strong> and the <code>Secret Key</code> into <strong>Recaptcha Secret</strong>.'),
            ],
        ],

        'fb' => [
            'provider'   => 'Facebook Messenger',
            'intro'      => __('The Messenger chat needs the numeric ID of the Facebook Page that will reply to visitors.'),
            'link'       => 'https://www.facebook.com/help/1503421039731588',
            'link_label' => __('How to find your Page ID'),
            'steps'      => [
                __('Open your Facebook Page and go to <strong>Settings</strong> → <strong>Page setup / About</strong>.'),
                __('Find the <strong>Page ID</strong> — a long number unique to your page.'),
                __('In Page <strong>Inbox → Chat plugin</strong> settings, allow your website domain so the widget can load.'),
                __('Paste the number into the <strong>Page Id</strong> field.'),
            ],
        ],

        'google-analytics' => [
            'provider'   => 'Google Analytics 4',
            'intro'      => __('GA4 tracks traffic using a Measurement ID that starts with "G-".'),
            'link'       => 'https://analytics.google.com/',
            'link_label' => __('Open Google Analytics'),
            'steps'      => [
                __('Sign in to Google Analytics and select your GA4 property.'),
                __('Go to <strong>Admin</strong> → <strong>Data Streams</strong> and open your web stream.'),
                __('Copy the <strong>Measurement ID</strong> (looks like <code>G-XXXXXXXXXX</code>).'),
                __('Paste it into the <strong>Ga Measurement Id</strong> field.'),
            ],
        ],

        'ipinfo' => [
            'provider'   => 'IPinfo.io',
            'intro'      => __('IPinfo resolves visitor IP addresses to location data using a single access token.'),
            'link'       => 'https://ipinfo.io/account/token',
            'link_label' => __('Open IPinfo token page'),
            'steps'      => [
                __('Create a free account at <code>ipinfo.io</code> and sign in.'),
                __('Go to your <strong>Dashboard</strong> → <strong>Token</strong>.'),
                __('Copy your <strong>Access Token</strong>.'),
                __('Paste it into the <strong>Access Token</strong> field.'),
            ],
        ],

        'tawk' => [
            'provider'   => 'Tawk.to',
            'intro'      => __('Tawk live chat needs two IDs taken from your widget embed link.'),
            'link'       => 'https://dashboard.tawk.to/',
            'link_label' => __('Open Tawk.to Dashboard'),
            'steps'      => [
                __('Sign in to your Tawk.to dashboard.'),
                __('Go to <strong>Administration</strong> → <strong>Channels</strong> → <strong>Chat Widget</strong>.'),
                __('Your widget link looks like <code>embed.tawk.to/PROPERTY_ID/WIDGET_ID</code>.'),
                __('Copy the first part into <strong>Property Id</strong> and the second into <strong>Widget Id</strong>.'),
            ],
        ],

        'coinmarketcap' => [
            'provider'   => 'CoinMarketCap',
            'intro'      => __('Used to fetch live exchange rates. A free Developer Portal API key is required.'),
            'link'       => 'https://pro.coinmarketcap.com/account',
            'link_label' => __('Open CoinMarketCap API keys'),
            'steps'      => [
                __('Sign in to the CoinMarketCap Developer Portal.'),
                __('Open the <strong>Account / API Key</strong> page.'),
                __('Copy your <strong>API Key</strong>.'),
                __('Paste it into the <strong>Api Key</strong> field.'),
            ],
        ],

        'currencylayer' => [
            'provider'   => 'currencylayer',
            'intro'      => __('Used to fetch live exchange rates via your currencylayer access key.'),
            'link'       => 'https://currencylayer.com/dashboard',
            'link_label' => __('Open currencylayer Dashboard'),
            'steps'      => [
                __('Sign in to your currencylayer account.'),
                __('Find your <strong>API Access Key</strong> on the dashboard.'),
                __('Copy the key.'),
                __('Paste it into the <strong>Api Key</strong> field.'),
            ],
        ],

        'pusher' => [
            'provider'   => 'Pusher Channels',
            'intro'      => __('Real-time notifications use a Pusher Channels app. All four values come from one screen.'),
            'link'       => 'https://dashboard.pusher.com/',
            'link_label' => __('Open Pusher Dashboard'),
            'steps'      => [
                __('Sign in to Pusher and open (or create) a <strong>Channels</strong> app.'),
                __('Go to the app\'s <strong>App Keys</strong> tab.'),
                __('Copy <code>app_id</code>, <code>key</code>, <code>secret</code> and <code>cluster</code>.'),
                __('Paste each value into its matching field below.'),
            ],
        ],

        'twilio' => [
            'provider'   => 'Twilio',
            'intro'      => __('Twilio sends SMS notifications. You need your account credentials and a Twilio phone number.'),
            'link'       => 'https://console.twilio.com/',
            'link_label' => __('Open Twilio Console'),
            'steps'      => [
                __('Sign in to the Twilio Console.'),
                __('On the dashboard copy your <strong>Account SID</strong> and <strong>Auth Token</strong>.'),
                __('Buy an SMS-capable number under <strong>Phone Numbers</strong> → <strong>Active numbers</strong>.'),
                __('Paste the SID, token and number (in <code>+E.164</code> format, e.g. <code>+15551234567</code>).'),
            ],
        ],

        'reloadly' => [
            'provider'   => 'Reloadly',
            'intro'      => __('Reloadly powers global airtime top-ups using OAuth client credentials.'),
            'link'       => 'https://www.reloadly.com/',
            'link_label' => __('Open Reloadly Dashboard'),
            'steps'      => [
                __('Sign in to your Reloadly dashboard.'),
                __('Go to <strong>Settings</strong> → <strong>API Settings</strong>.'),
                __('Copy your <strong>Client ID</strong> and <strong>Client Secret</strong>.'),
                __('Keep <strong>Sandbox</strong> on for testing, turn it off to go live.'),
            ],
        ],

        'http' => [
            'provider'   => __('your recharge API provider'),
            'intro'      => __('Connect any custom or third-party recharge API. These values come from that provider\'s documentation.'),
            'link'       => null,
            'link_label' => null,
            'steps'      => [
                __('Get the API <strong>Base URL</strong>, <strong>Endpoint</strong> path and access <strong>Token</strong> from your provider.'),
                __('Set a sensible request <strong>Timeout</strong> (seconds) — 15 is a safe default.'),
                __('Paste each value into its matching field below.'),
            ],
        ],

        'sandbox' => [
            'provider'   => __('Sandbox'),
            'intro'      => __('This is a built-in testing driver. No external credentials are required — use it to simulate recharges safely.'),
            'link'       => null,
            'link_label' => null,
            'steps'      => [],
        ],
    ];

    $guide = $guides[$plugin->code] ?? null;
@endphp

@if($guide)
    <div class="settings-plugin-guide">
        <div class="settings-plugin-guide__head">
            <span class="settings-plugin-guide__icon" aria-hidden="true">
                <i class="fa-solid fa-circle-info"></i>
            </span>
            <div>
                <span class="settings-plugin-guide__eyebrow">{{ __('Setup Guide') }}</span>
                <h6>{{ __('Where to find your :provider credentials', ['provider' => $guide['provider']]) }}</h6>
            </div>
        </div>

        @if(! empty($guide['intro']))
            <p class="settings-plugin-guide__intro">{{ $guide['intro'] }}</p>
        @endif

        @if(! empty($guide['steps']) || ! empty($guide['link']))
            <details class="settings-plugin-guide__more">
                <summary class="settings-plugin-guide__toggle">
                    <span class="settings-plugin-guide__toggle-label">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                        <span class="settings-plugin-guide__toggle-closed">{{ __('Show setup steps') }}</span>
                        <span class="settings-plugin-guide__toggle-open">{{ __('Hide setup steps') }}</span>
                    </span>
                    <i class="fa-solid fa-chevron-down settings-plugin-guide__chevron" aria-hidden="true"></i>
                </summary>
                <div class="settings-plugin-guide__panel">
                    @if(! empty($guide['steps']))
                        <ol class="settings-plugin-guide__steps">
                            @foreach($guide['steps'] as $step)
                                <li>{!! $step !!}</li>
                            @endforeach
                        </ol>
                    @endif

                    @if(! empty($guide['link']))
                        <a href="{{ $guide['link'] }}" target="_blank" rel="noopener noreferrer" class="settings-plugin-guide__link">
                            <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i>
                            <span>{{ $guide['link_label'] ?? __('Open provider dashboard') }}</span>
                        </a>
                    @endif
                </div>
            </details>
        @endif
    </div>
@endif
