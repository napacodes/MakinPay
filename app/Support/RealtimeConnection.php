<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Whether this site can push to a client in real time, and where a client
 * connects to hear it.
 *
 * ONE READER. A site has realtime through the Pusher plugin (the admin's
 * Plugins screen), or through its own Reverb server (BROADCAST_CONNECTION=
 * reverb with REVERB_* keys). The notification channels, the admin's notify
 * screen and the app's GET /config all ask here, so they cannot disagree
 * about whether a notification is broadcast or where to listen for it.
 *
 * ONLY THE APP KEY LEAVES THE SERVER. In the Pusher protocol the key names
 * the app and authorises nothing; the secret signs each private-channel
 * subscription in /broadcasting/auth. The secret and the app id are read to
 * check they are set and are never returned.
 *
 * A Reverb install still on the keys .env.example ships is treated as not
 * configured: that secret is public, so anyone could sign a subscription to
 * any customer's channel with it. Nothing changes for such an install - it
 * never broadcast before this reader existed.
 *
 * Nor does a Reverb install on the sync queue. There a broadcast runs inline,
 * inside whatever sent the notification, and a Reverb server that is down or
 * unreachable would hold a money flow's locks for the length of a connect
 * timeout. Such an install never broadcast before either; the app refreshes
 * on resume instead.
 */
final class RealtimeConnection
{
    /**
     * The event every feed notification broadcasts as.
     */
    public const string EVENT = 'notification.received';

    /**
     * The customer's private channel; the app puts its own id in.
     */
    public const string USER_CHANNEL = 'private-App.Models.User.{id}';

    /**
     * The REVERB_APP_KEY and REVERB_APP_SECRET .env.example has shipped.
     *
     * @var list<string>
     */
    private const array SHIPPED_EXAMPLE_KEYS = ['cmxh7opwhiuzluppyx1h', 'njphoxvf0yj5ndbfp5zc'];

    /**
     * Whether a notification should go out on the broadcast channel.
     *
     * The Pusher plugin answers exactly as the channels always read it, its
     * status alone, so an install already on Pusher sees no change.
     */
    public static function canBroadcast(): bool
    {
        try {
            return self::pusherSwitchedOn() || self::reverbReady();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Where a client connects, or null when there is nowhere to.
     *
     * @return array{driver: string, key: string, host: string, port: int, tls: bool, cluster: string|null, event: string, channel: string}|null
     */
    public static function client(): ?array
    {
        try {
            return self::pusherClient() ?? self::reverbClient();
        } catch (Throwable) {
            return null;
        }
    }

    private static function pusherSwitchedOn(): bool
    {
        return (bool) (self::pusher()['status'] ?? false);
    }

    /**
     * The Pusher plugin, when BroadcastServiceProvider would point
     * broadcasting at it: switched on, with the key, secret and app id set.
     *
     * @return array{driver: string, key: string, host: string, port: int, tls: bool, cluster: string|null, event: string, channel: string}|null
     */
    private static function pusherClient(): ?array
    {
        $pusher = self::pusher();

        if (! self::pusherSwitchedOn() || ! self::filled($pusher, ['pusher_app_key', 'pusher_app_secret', 'pusher_app_id'])) {
            return null;
        }

        // The same default BroadcastServiceProvider falls back to.
        $cluster = trim((string) ($pusher['pusher_app_cluster'] ?? '')) ?: 'mt1';

        return [
            'driver'  => 'pusher',
            'key'     => trim((string) $pusher['pusher_app_key']),
            'host'    => 'ws-'.$cluster.'.pusher.com',
            'port'    => 443,
            'tls'     => true,
            'cluster' => $cluster,
            'event'   => self::EVENT,
            'channel' => self::USER_CHANNEL,
        ];
    }

    /**
     * @return array{driver: string, key: string, host: string, port: int, tls: bool, cluster: string|null, event: string, channel: string}|null
     */
    private static function reverbClient(): ?array
    {
        if (! self::reverbReady()) {
            return null;
        }

        return [
            'driver'  => 'reverb',
            'key'     => trim((string) config('broadcasting.connections.reverb.key')),
            'host'    => trim((string) config('broadcasting.connections.reverb.options.host')),
            'port'    => (int) config('broadcasting.connections.reverb.options.port', 443),
            'tls'     => (bool) config('broadcasting.connections.reverb.options.useTLS', true),
            'cluster' => null,
            'event'   => self::EVENT,
            'channel' => self::USER_CHANNEL,
        ];
    }

    /**
     * Reverb is the driver in use, broadcasts off the request on a real
     * queue, and has an address and keys that are set and are not the public
     * example ones.
     */
    private static function reverbReady(): bool
    {
        if (config('broadcasting.default') !== 'reverb' || config('queue.default') === 'sync') {
            return false;
        }

        // Without a host the broadcaster has nowhere to send, so every push
        // would fail while the admin is told delivery is live.
        if (trim((string) config('broadcasting.connections.reverb.options.host', '')) === '') {
            return false;
        }

        $reverb = (array) config('broadcasting.connections.reverb', []);

        if (! self::filled($reverb, ['key', 'secret', 'app_id'])) {
            return false;
        }

        return ! in_array(trim((string) $reverb['key']), self::SHIPPED_EXAMPLE_KEYS, true)
            && ! in_array(trim((string) $reverb['secret']), self::SHIPPED_EXAMPLE_KEYS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pusher(): array
    {
        $credentials = pluginCredentials('pusher');

        return is_array($credentials) ? $credentials : [];
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $keys
     */
    private static function filled(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string) ($values[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
