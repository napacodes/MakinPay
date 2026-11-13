<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Letting the app subscribe to its own private channels.
 *
 * The browser's `/broadcasting/auth` runs on the `web` middleware and answers
 * to a session cookie, which a phone does not have. This is the same question
 * asked on the token guard, and it is a SEPARATE endpoint on purpose: the two
 * have different ways of knowing who is asking, and a change to one must not
 * be able to widen the other.
 *
 * What this does NOT do is decide who may listen. That is
 * routes/channels.php's, and it stays there - a websocket subscription is not
 * covered by any route middleware, so the channel closures are the only thing
 * between one customer's notification stream and another's. This endpoint
 * only establishes who is asking and hands the question over.
 *
 * The reply is Laravel's own auth payload verbatim, not the API envelope: it
 * is consumed by laravel-echo, which expects exactly what the broadcaster
 * returns. Refusals ARE enveloped, because those are read by the app rather
 * than by the client library.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'socket_id'    => ['required', 'string', 'max:255'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        /*
         * REFUSED OUTRIGHT when the installation has no real broadcaster.
         *
         * The `null` and `log` drivers override auth() to do nothing at all -
         * they answer every channel name with success, including one nobody
         * registered. Delegating to them would turn this endpoint into a
         * rubber stamp on any site that has not configured websockets, which
         * is most of them. Nothing is listening on such a site anyway, so the
         * honest answer is no.
         *
         * Found by a test that asked for a channel that does not exist and got
         * a 200.
         */
        if (! $this->broadcasterAuthorises()) {
            return ApiResponse::error(ApiErrorCode::FeatureDisabled, __('Realtime updates are not available.'));
        }

        /*
         * Broadcast::auth resolves the subscriber through the guards the
         * channel was registered with, defaulting to the application's own.
         * Naming sanctum here is what makes a token count as a signed-in user
         * for a channel that did not name a guard of its own.
         */
        Auth::shouldUse('sanctum');

        try {
            $payload = Broadcast::auth($request);
        } catch (AccessDeniedHttpException) {
            /*
             * Somebody else's channel, or one nobody registered. Both reach
             * the broadcaster as a failure to match a rule and both are
             * refused the same way, without saying which: the channel name is
             * the only thing the caller supplied, and telling the two apart is
             * a way to enumerate what exists.
             *
             * ONLY this exception is caught. A catch-all here turned a
             * misconfigured broadcaster into "you may not listen", which is
             * indistinguishable from a real refusal and sent me looking for a
             * permissions bug that was a missing app key.
             */
            return ApiResponse::error(ApiErrorCode::Forbidden);
        }

        return response()->json(
            is_string($payload) ? json_decode($payload, true) : $payload,
        );
    }

    /**
     * Whether the configured broadcaster actually enforces channel rules.
     *
     * The list is of the drivers that DO NOT, rather than the ones that do, so
     * a driver added to the framework later is trusted by default and a site
     * using it is not silently cut off from realtime.
     */
    private function broadcasterAuthorises(): bool
    {
        $connection = (string) config('broadcasting.default');

        if ($connection === '' || $connection === 'null' || $connection === 'log') {
            return false;
        }

        $driver = (string) config("broadcasting.connections.{$connection}.driver", $connection);

        return ! in_array($driver, ['null', 'log'], true);
    }
}
