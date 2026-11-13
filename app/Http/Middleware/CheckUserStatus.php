<?php

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        /*
         * An inactive account and a suspended one are refused the same way;
         * only the message differs, because a suspension is temporary and
         * the user is told how to appeal it.
         */
        $message = match (true) {
            $user->status !== UserStatus::ACTIVE => __('Your account is not active. Please contact support.'),
            $user->isSuspended()                 => self::suspendedMessage($user),
            default                              => null,
        };

        if ($message === null) {
            return $next($request);
        }

        /*
         * The mobile API answers in its own envelope so the app can branch on
         * a stable code. Only requests that opted in through
         * UseApiErrorEnvelope take this path; the shape below is what every
         * existing JSON caller already receives and is left exactly as it is.
         */
        if (UseApiErrorEnvelope::wants($request)) {
            return ApiResponse::error(
                $user->isSuspended() ? ApiErrorCode::AccountSuspended : ApiErrorCode::Forbidden,
                $message,
            );
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'status'  => false,
                'message' => $message,
            ], 403);
        }

        auth()->logout();

        return redirect()->route('user.login')
            ->withErrors(['error' => $message]);
    }

    public static function suspendedMessage(User $user): string
    {
        $until = $user->suspended_until;

        if ($until === null) {
            return __('Your account is suspended. Please contact support to appeal.');
        }

        return __('Your account is suspended until :date. Please contact support to appeal.', [
            'date' => $until->timezone(setting('site_timezone', config('app.timezone', 'UTC')))->format('M d, Y H:i'),
        ]);
    }
}
