@php
    /**
     * The layout's own area comes first; the other feed stays available only as a
     * fallback, and the browser confirms the container that page actually renders.
     */
    $notificationPollArea = $notificationArea ?? (auth('admin')->check() ? 'admin' : 'user');

    $notificationPollFeeds = [
        'admin' => fn (): array => [
            'container'    => '#append-new-admin-notification',
            'signatureUrl' => route('admin.notifications.signature'),
            'recentUrl'    => route('admin.notifications.recent'),
            'initial'      => auth('admin')->user()->notificationSignature(),
        ],
        'user' => fn (): array => [
            'container'    => '.append-new-notification',
            'signatureUrl' => route('user.notifications.signature'),
            'recentUrl'    => route('user.notifications.recent'),
            'initial'      => auth()->user()->notificationSignature(),
        ],
    ];

    $notificationPollTargets = collect([$notificationPollArea, $notificationPollArea === 'admin' ? 'user' : 'admin'])
        ->filter(fn (string $area): bool => notificationNotifiable($area) !== null)
        ->map(fn (string $area): array => $notificationPollFeeds[$area]())
        ->values()
        ->all();
@endphp

@if(! empty($notificationPollTargets))
    <script>
        (function (window, document, $) {
            'use strict';

            const targets = @json($notificationPollTargets);
            const pollInterval = 30000;
            const realtimeInterval = 180000;

            const config = targets.find(target => $(target.container).length > 0);

            if (!config) {
                return;
            }

            let lastSignature = config.initial;
            let requestInFlight = false;
            let timer = null;

            const currentInterval = () => window.DigiKashRealtimeActive
                ? realtimeInterval
                : pollInterval;

            const renderDropdown = () => {
                $.get(config.recentUrl, function (html) {
                    $(config.container).html(html);
                });
            };

            const applySignature = (signature, options) => {
                const grew = signature.count > lastSignature.count;

                lastSignature = signature;
                renderDropdown();

                // No argument: the player owns which tune this account plays.
                if (grew && options.playTune && window.DigiKashNotificationTune) {
                    window.DigiKashNotificationTune.play();
                }
            };

            /**
             * Ask the server for the unread fingerprint and only repaint the
             * dropdown when it actually differs from what the page is showing.
             */
            const sync = (options) => {
                const settings = Object.assign({ playTune: false, force: false }, options || {});

                if (requestInFlight) {
                    return;
                }

                requestInFlight = true;

                $.getJSON(config.signatureUrl)
                    .done(function (signature) {
                        const changed = signature.count !== lastSignature.count
                            || signature.latest !== lastSignature.latest;

                        if (settings.force || changed) {
                            applySignature(signature, settings);
                        }
                    })
                    .always(function () {
                        requestInFlight = false;
                    });
            };

            const poll = () => {
                if (document.hidden) {
                    return;
                }

                sync({ playTune: true });
            };

            const restart = () => {
                if (timer) {
                    clearInterval(timer);
                }

                timer = setInterval(poll, currentInterval());
            };

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    sync({ playTune: true });
                }
            });

            // Exposed so the realtime handler can repaint without racing the poller.
            window.DigiKashNotificationFeed = {
                sync: sync,
                restart: restart
            };

            restart();
        })(window, document, jQuery);
    </script>
@endif
