@php
    use App\Constants\Status;

    /*
     |--------------------------------------------------------------------------
     | The browser's realtime connection
     |--------------------------------------------------------------------------
     |
     | ONE PARTIAL, TWO DRIVERS. Reverb speaks the Pusher protocol, so the same
     | pusher-js client talks to both and only the connection options differ -
     | everything below the connection (the channel name, the subscription, the
     | notification handling) is identical and is written once.
     |
     | This file was `_pusher_config` and only ever configured Pusher, which is
     | why an installation running Reverb had a websocket server, channel rules
     | and an auth endpoint, and nothing in any browser connecting to them.
     |
     | The driver is read from config rather than guessed: BroadcastServiceProvider
     | forces `pusher` when the operator has that plugin configured, so whatever
     | `broadcasting.default` says at this point is the one actually in use.
     |
     | ONLY THE APP KEY REACHES THE BROWSER. That is public by design in this
     | protocol - it names the app, it does not authorise anything. The secret
     | stays server-side and is what /broadcasting/auth signs with; printing it
     | here would hand every visitor the ability to sign their own channel
     | subscriptions.
     */

    $realtimeArea       = ($notificationArea ?? (auth('admin')->check() ? 'admin' : 'user')) === 'admin';
    $realtimeNotifiable = notificationNotifiable($realtimeArea ? 'admin' : 'user');

    $realtimeKey     = null;
    $realtimeOptions = [];

    $pusherCredentials = pluginCredentials('pusher');

    if (($pusherCredentials['status'] ?? false) == Status::TRUE) {
        $realtimeKey     = $pusherCredentials['pusher_app_key'] ?? '';
        $realtimeOptions = [
            'cluster'   => $pusherCredentials['pusher_app_cluster'] ?? '',
            'encrypted' => true,
        ];
    } elseif (config('broadcasting.default') === 'reverb') {
        $reverb       = config('broadcasting.connections.reverb', []);
        $reverbPort   = (int) ($reverb['options']['port'] ?? 443);
        $reverbSecure = (bool) ($reverb['options']['useTLS'] ?? true);

        $realtimeKey     = $reverb['key'] ?? '';
        $realtimeOptions = [
            // Reverb is the operator's own server, so the client is told where
            // it is rather than looking it up in a cluster it is not part of.
            'wsHost'            => $reverb['options']['host'] ?? '',
            'wsPort'            => $reverbPort,
            'wssPort'           => $reverbPort,
            'forceTLS'          => $reverbSecure,
            'enabledTransports' => ['ws', 'wss'],
            // No stats to send home: there is no Pusher account behind this.
            'disableStats' => true,
            // pusher-js insists on a cluster existing even where it means
            // nothing, and an absent one makes it guess an endpoint that is
            // not there.
            'cluster' => '',
        ];
    }
@endphp

@if($realtimeKey && $realtimeNotifiable)
    <script src="https://js.pusher.com/7.0/pusher.min.js"></script>

    <script>
        (function () {
            'use strict';

            const authEndpoint = '/broadcasting/auth';

            // Ensure CSRF token is available
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            if (!csrfToken) {
                console.error('CSRF token not found!');
                return;
            }

            const pusher = new Pusher(@json($realtimeKey), Object.assign(@json($realtimeOptions), {
                authEndpoint: authEndpoint,
                auth: {
                    headers: {
                        'X-CSRF-TOKEN': csrfToken
                    }
                }
            }));

            const isAdmin = @json($realtimeArea);
            const userId = {{ $realtimeNotifiable->id }};
            const channelName = isAdmin
                ? `private-App.Models.Admin.${userId}`
                : `private-App.Models.User.${userId}`;


            // Helper to subscribe to a channel and bind notification event
            const subscribeToChannel = (channelName, callback) => {

                const channel = pusher.subscribe(channelName);

                channel.bind('pusher:subscription_succeeded', function () {
                    // Lets the poller fall back to a slow safety-net interval.
                    window.DigiKashRealtimeActive = true;

                    if (window.DigiKashNotificationFeed) {
                        window.DigiKashNotificationFeed.restart();
                    }
                });

                channel.bind('pusher:subscription_error', function () {
                    window.DigiKashRealtimeActive = false;

                    if (window.DigiKashNotificationFeed) {
                        window.DigiKashNotificationFeed.restart();
                    }
                });

                channel.bind('notification.received', function(data) {
                    if (typeof callback === 'function') callback(data);
                });
            };

            // Subscribe to user/admin-specific channel and fetch notifications
            subscribeToChannel(channelName, function (data) {
                // No argument: the player owns which tune this account plays.
                if (window.DigiKashNotificationTune) {
                    window.DigiKashNotificationTune.play();
                }

                // The poller owns the markup so both paths cannot double-render.
                if (window.DigiKashNotificationFeed) {
                    window.DigiKashNotificationFeed.sync({ force: true });

                    return;
                }

                const $notificationDropdown = isAdmin
                    ? $('#append-new-admin-notification')
                    : $('.append-new-notification');

                const notificationUrl = isAdmin
                    ? '{{ route('admin.notifications.recent') }}'
                    : '{{ route('user.notifications.recent') }}';

                $.get(notificationUrl, function (response) {
                    $notificationDropdown.html(response); // Update notifications
                });
            });

        })();
    </script>
@endif
