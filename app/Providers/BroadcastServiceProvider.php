<?php

namespace App\Providers;

use App\Constants\Status;
use App\Notifications\Channels\ResilientBroadcastChannel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Every notification's 'broadcast' channel is the resilient one: a
        // live push that fails is logged, and never takes the notification -
        // or, on a sync queue, the money flow that sent it - down with it.
        $this->app->afterResolving(ChannelManager::class, function (ChannelManager $channels): void {
            $channels->extend('broadcast', fn (Application $app): ResilientBroadcastChannel => new ResilientBroadcastChannel($app->make(Dispatcher::class)));
        });
    }

    /**
     * Bootstrap services.
     *
     * ORDER IS LOAD-BEARING HERE, in a way that is easy to get wrong twice.
     *
     * `Broadcast::channel()` proxies to the CURRENT default driver instance,
     * so channel rules land on whichever driver was default at the moment the
     * file was required. Requiring them before the Pusher switch leaves the
     * Pusher driver with no rules; requiring them only inside the Pusher
     * branch - which is what this used to do - leaves a Reverb installation
     * with no rules at all, which is the defect the mobile API's own
     * broadcasting-auth endpoint ran into.
     *
     * So the driver is settled first, and the rules are registered once,
     * afterwards, on whatever that turned out to be.
     */
    public function boot(): void
    {
        $this->configurePusherWhenEnabled();

        /*
         * Registering the rules and the route below resolves the default
         * broadcaster, and the Pusher SDK behind the reverb and pusher
         * drivers throws a TypeError on a null key. From here that is every
         * page and every API call answering 500 - which is what an
         * installation set to BROADCAST_CONNECTION=reverb with no keys did.
         * Without keys nothing can broadcast anyway, so the site stays up
         * and live updates simply are not there until the keys are.
         */
        if (! $this->defaultBroadcasterHasKeys()) {
            return;
        }

        /*
         * Always, in every environment including testing. These are closures
         * deciding who may listen; they cost nothing when nothing is
         * broadcasting, and without them in testing no test can reach a
         * channel rule - which is how the rules went untested until now.
         */
        if (file_exists(base_path('routes/channels.php'))) {
            require base_path('routes/channels.php');
        }

        /*
         * The browser's own endpoint, on the session guard. The app has its
         * own at api/v1/broadcasting/auth, on the token guard - deliberately
         * separate, so a change to one cannot widen the other.
         *
         * Registered for EVERY driver. It used to sit at the end of the
         * Pusher branch, so an installation broadcasting through Reverb had a
         * websocket server and no way for a browser to authorise a private
         * subscription to it - the same shape of defect as the channel rules
         * above, and the other half of it.
         *
         * It adds no access: the route is behind the `web` middleware and
         * every channel is behind the closures in channels.php, which is
         * where the authorisation actually happens.
         *
         * The middleware is named explicitly although Laravel defaults to the
         * same thing, so dropping it survives mutation. Kept because the
         * guard this route runs under is the whole difference between it and
         * the app's token-guarded one, and a reader should not have to know
         * the framework default to see which.
         */
        Broadcast::routes(['middleware' => ['web']]);
    }

    /**
     * Whether the default broadcaster can be built. The null and log drivers
     * always can; the Pusher-protocol drivers (reverb, pusher) need a key,
     * secret and app id, and refuse to construct without them.
     */
    private function defaultBroadcasterHasKeys(): bool
    {
        $name       = (string) config('broadcasting.default');
        $connection = config("broadcasting.connections.{$name}");

        if (! is_array($connection)) {
            return in_array($name, ['null', 'log', ''], true);
        }

        if (! in_array($connection['driver'] ?? null, ['reverb', 'pusher'], true)) {
            return true;
        }

        return filled($connection['key'] ?? null)
            && filled($connection['secret'] ?? null)
            && filled($connection['app_id'] ?? null);
    }

    /**
     * Point broadcasting at the operator's Pusher account, if they have one.
     *
     * Leaves the configured driver alone otherwise, so an installation running
     * Reverb keeps running Reverb.
     */
    private function configurePusherWhenEnabled(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        // Fetch Pusher configuration
        $pusherConfig = function_exists('pluginCredentials') ? pluginCredentials('pusher') : [];

        // Ensure Pusher is enabled before setting configuration
        if (! isset($pusherConfig['status']) || $pusherConfig['status'] !== Status::TRUE) {
            return;
        }

        // Validate required Pusher keys
        if (
            empty($pusherConfig['pusher_app_key']) || empty($pusherConfig['pusher_app_secret']) || empty($pusherConfig['pusher_app_id'])
        ) {
            return;
        }

        // The Pusher SDK prefers an explicit "host" over "cluster", so the host
        // has to be derived from the configured cluster. A hardcoded one sends
        // every tenant's traffic to the same cluster and Pusher answers with
        // "App key ... not in this cluster" for everyone hosted elsewhere.
        $cluster = trim((string) ($pusherConfig['pusher_app_cluster'] ?? '')) ?: 'mt1';

        // Set Pusher configuration dynamically
        Config::set('broadcasting.default', 'pusher');

        Config::set('broadcasting.connections.pusher', [
            'driver'  => 'pusher',
            'key'     => $pusherConfig['pusher_app_key'],
            'secret'  => $pusherConfig['pusher_app_secret'],
            'app_id'  => $pusherConfig['pusher_app_id'],
            'options' => [
                'cluster'   => $cluster,
                'useTLS'    => true,
                'host'      => 'api-'.$cluster.'.pusher.com',
                'port'      => 443,
                'scheme'    => 'https',
                'encrypted' => true,
            ],
        ]);

    }
}
