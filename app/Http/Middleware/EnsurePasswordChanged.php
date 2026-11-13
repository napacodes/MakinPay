<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a user on the change-password screen after an admin set a temporary
 * password with "require change at next sign-in". Only the password screens
 * and the sign-out route are reachable until the flag clears, which
 * `SettingController::passwordUpdate()` does once a new password is saved.
 */
class EnsurePasswordChanged
{
    /** @var list<string> */
    private const ALLOWED_ROUTES = [
        'user.settings.password.change',
        'user.settings.password.update',
        'user.logout',
    ];

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! (bool) $user->getAttribute('must_change_password')) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        $message = __('Please set a new password before continuing.');

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => false, 'message' => $message], 403);
        }

        notifyEvs('warning', $message);

        return redirect()->route('user.settings.password.change');
    }
}
