<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route as belonging to the mobile API, so the exception handler knows
 * to render the `{message, code, errors}` envelope for it.
 *
 * Opting in is a property of the route rather than a URL prefix on purpose.
 * The merchant server-to-server API already lives under `api/v1/` and answers
 * with its own `{error, message}` body, which live integrations parse today; a
 * path test would need a list of which `api/v1/` routes are which, and that
 * list would be wrong the first time a route was renamed.
 *
 * `wants()` reads the route's declared middleware and not only the attribute
 * this class sets, because running order cannot be relied on. `auth` and
 * `throttle` both sit in the framework's middleware priority list and this
 * does not, so they are sorted ahead of it however the group is written - and
 * those two are exactly the middleware whose failures most need the envelope.
 * Asking the route what it declared is a question with the same answer before
 * and after any of them run.
 */
class UseApiErrorEnvelope
{
    /**
     * Request attribute set once this has run.
     */
    public const ATTRIBUTE = 'api_error_envelope';

    /**
     * The alias a route group opts in with.
     */
    public const ALIAS = 'api.envelope';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        return $next($request);
    }

    /**
     * Whether the given request is for a route that opted in.
     */
    public static function wants(Request $request): bool
    {
        if ($request->attributes->getBoolean(self::ATTRIBUTE)) {
            return true;
        }

        $route = $request->route();

        return $route instanceof Route
            && in_array(self::ALIAS, $route->gatherMiddleware(), true);
    }
}
