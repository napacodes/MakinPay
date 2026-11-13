<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Enums\UserRole;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a mobile API route to one of the platform's three roles.
 *
 * Spatie's `role` middleware is not what this needs: that reads the
 * permissions tables the admin panel uses, while a customer, merchant or agent
 * is distinguished by User::role.
 *
 * Two checks, not one. The token has to carry the matching ability, and the
 * account has to still hold the role. The ability alone would let a token
 * minted before a demotion keep working; the account alone would let a token
 * scoped to something narrower reach further than it was issued for. Neither
 * on its own is the question being asked.
 */
class EnsureApiRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated);
        }

        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if (! in_array($role, $roles, true)) {
            return ApiResponse::error(ApiErrorCode::Forbidden);
        }

        $token = $user->currentAccessToken();

        // A transient token - the session-backed guard, or Sanctum::actingAs -
        // carries every ability by definition, so there is nothing to check.
        if ($token !== null && method_exists($token, 'can') && ! $token->can('role:'.$role)) {
            return ApiResponse::error(ApiErrorCode::Forbidden);
        }

        return $next($request);
    }
}
