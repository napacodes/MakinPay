<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `verified` middleware, switched on and off by the administrator.
 *
 * Implementing the MustVerifyEmail contract on the User model is what finally
 * makes registration send a verification email — but it also arms the stock
 * `verified` middleware for everyone at the same moment. On a site where
 * verification never worked, most accounts have a null `email_verified_at`,
 * so arming it unconditionally would lock out nearly every existing customer
 * on the deploy that shipped it.
 *
 * Enforcement is therefore an administrator's decision, taken in Security
 * Settings and off by default. While it is off this passes everyone through;
 * while it is on it defers to the framework's own middleware so the redirect,
 * the JSON response and the `verification.notice` route all behave exactly as
 * Laravel documents.
 *
 * Turning it on does gate existing unverified users — that is the point of
 * the switch, and the verification screen they land on can resend the link.
 */
class EnsureEmailIsVerifiedWhenRequired
{
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! User::emailVerificationRequired()) {
            return $next($request);
        }

        return app(EnsureEmailIsVerified::class)->handle($request, $next, $redirectToRoute);
    }
}
