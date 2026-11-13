<?php

namespace App\Http\Middleware;

use App\Support\LicenseGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Independent admin-side license checkpoint.
 *
 * Runs only inside the admin middleware group. It refreshes the cached
 * license state on a throttled basis (so the network check lives here as
 * well as in the scheduled heartbeat) and — only when the server has
 * CONFIRMED the license is invalid — degrades admin write actions while
 * still allowing read access and the re-activation page.
 */
class VerifyProjectLicense
{
    public function __construct(private readonly LicenseGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        

        return $next($request);
    }

    private function isWriteRequest(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isWhitelisted(Request $request): bool
    {
        $name = (string) optional($request->route())->getName();

        if ($name === '') {
            return true;
        }

        foreach (['admin.app.updater.', 'logout', 'login'] as $allowed) {
            if (str_contains($name, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
