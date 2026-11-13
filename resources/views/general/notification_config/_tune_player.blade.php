@php
    use App\Support\NotificationTuneLibrary;

    $notificationNotifiable = notificationNotifiable($notificationArea ?? null);
    $notificationTunePayload = function (array $tune): array {
        if (isset($tune['file'])) {
            $tune['file_url'] = asset($tune['file']);
        }

        return $tune;
    };

    $notificationTuneConfig = [
        'notificationsEnabled' => $notificationNotifiable
            ? $notificationNotifiable->notificationDeliveryEnabled()
            : (bool) setting('notification_delivery_enabled', true),
        'soundEnabled' => $notificationNotifiable
            ? $notificationNotifiable->notificationSoundEnabled()
            : (bool) setting('notification_tune_sound_enabled', true),
        'defaultTuneKey' => NotificationTuneLibrary::defaultKey(),
        'tuneKey'        => $notificationNotifiable
            ? $notificationNotifiable->notificationTuneKey()
            : NotificationTuneLibrary::defaultKey(),
        'tune'           => $notificationNotifiable
            ? $notificationTunePayload($notificationNotifiable->notificationTunePayload())
            : $notificationTunePayload(NotificationTuneLibrary::resolve(NotificationTuneLibrary::defaultKey())),
        'tunes' => collect(NotificationTuneLibrary::tunes())
            ->map(fn (array $tune): array => $notificationTunePayload($tune))
            ->all(),
    ];
@endphp

<script>
    (function (window, document) {
        'use strict';

        const initialConfig = @json($notificationTuneConfig);
        let audioContext = null;
        let state = Object.assign({
            notificationsEnabled: true,
            soundEnabled: true,
            defaultTuneKey: 'pulse',
            tuneKey: null,
            tune: null,
            tunes: {}
        }, initialConfig);

        const getAudioContext = function () {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return null;
            }

            if (!audioContext) {
                audioContext = new AudioContextClass();
            }

            if (audioContext.state === 'suspended') {
                audioContext.resume().catch(function () {});
            }

            return audioContext;
        };

        const unlockAudio = function () {
            getAudioContext();
        };

        ['click', 'keydown', 'touchstart'].forEach(function (eventName) {
            document.addEventListener(eventName, unlockAudio, { once: true, passive: true });
        });

        const getTune = function (key) {
            return state.tunes[key] || state.tunes[state.defaultTuneKey] || state.tune;
        };

        const getDefaultTune = function () {
            return getTune(state.defaultTuneKey);
        };

        const isPlayableTune = function (payload) {
            if (!payload) {
                return false;
            }

            return (Array.isArray(payload.steps) && payload.steps.length > 0)
                || Boolean(payload.file_url)
                || Boolean(payload.file);
        };

        /**
         * Only a real tune descriptor (preview) overrides the account's choice.
         * Everything else resolves to the tune this account selected — by
         * payload, then by key — and the platform default is the last resort.
         */
        const normalizeTune = function (payload) {
            if (isPlayableTune(payload)) {
                return payload;
            }

            if (isPlayableTune(state.tune)) {
                return state.tune;
            }

            const selected = state.tuneKey ? state.tunes[state.tuneKey] : null;

            if (isPlayableTune(selected)) {
                return selected;
            }

            return getDefaultTune();
        };

        const tuneFileUrl = function (tune) {
            if (!tune) {
                return null;
            }

            if (tune.file_url) {
                return tune.file_url;
            }

            if (tune.file) {
                return '/' + String(tune.file).replace(/^\/+/, '');
            }

            return null;
        };

        const playGenerated = function (tune) {
            const context = getAudioContext();

            if (!context || !tune || !Array.isArray(tune.steps)) {
                return;
            }

            let startAt = context.currentTime + 0.015;

            tune.steps.forEach(function (step) {
                const duration = Math.max(0.04, Number(step.duration || 0.12));
                const gap = Math.max(0, Number(step.gap || 0.025));
                const frequency = Number(step.frequency || 0);

                if (frequency > 0) {
                    const oscillator = context.createOscillator();
                    const gain = context.createGain();
                    const volume = Math.max(0.01, Math.min(0.25, Number(step.volume || 0.14)));

                    oscillator.type = step.type || 'sine';
                    oscillator.frequency.setValueAtTime(frequency, startAt);

                    gain.gain.setValueAtTime(0.0001, startAt);
                    gain.gain.exponentialRampToValueAtTime(volume, startAt + 0.018);
                    gain.gain.exponentialRampToValueAtTime(0.0001, startAt + duration);

                    oscillator.connect(gain);
                    gain.connect(context.destination);
                    oscillator.start(startAt);
                    oscillator.stop(startAt + duration + 0.02);
                }

                startAt += duration + gap;
            });
        };

        const playFile = function (tune) {
            const source = tuneFileUrl(tune);

            if (!source || !window.Audio) {
                return false;
            }

            const audio = new Audio(source);
            audio.volume = 0.75;
            audio.play().catch(function () {
                playGenerated(tune);
            });

            return true;
        };

        const play = function (payload, force) {
            if (!force && (!state.notificationsEnabled || !state.soundEnabled)) {
                return;
            }

            const tune = normalizeTune(payload);

            if (!playFile(tune)) {
                playGenerated(tune);
            }
        };

        window.DigiKashNotificationTune = {
            play: play,
            preview: function (payload) {
                play(payload, true);
            },
            getTune: getTune,
            getDefaultTune: getDefaultTune,
            /**
             * What this page will actually play — run it in the console to see
             * whether the browser received the tune the account has saved.
             */
            resolved: function () {
                const tune = normalizeTune(null);

                return {
                    tuneKey: state.tuneKey,
                    defaultTuneKey: state.defaultTuneKey,
                    playing: tune ? (tune.key || tune.label) : null,
                    source: tuneFileUrl(tune),
                    soundEnabled: state.soundEnabled
                };
            },
            setConfig: function (config) {
                state = Object.assign(state, config || {});
                this.tunes = state.tunes;
            },
            tunes: state.tunes
        };
    })(window, document);
</script>
