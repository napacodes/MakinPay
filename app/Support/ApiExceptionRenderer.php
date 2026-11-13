<?php

namespace App\Support;

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\IdempotencyConflictException;
use App\Http\Middleware\UseApiErrorEnvelope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns a thrown exception into the mobile API's error envelope.
 *
 * Only requests that passed through UseApiErrorEnvelope are handled; everything
 * else - the web panel, and the merchant server-to-server API that answers with
 * its own `{error, message}` body - is handed back to Laravel untouched.
 */
final class ApiExceptionRenderer
{
    /**
     * @return JsonResponse|null Null means "not mine, let Laravel render it".
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! UseApiErrorEnvelope::wants($request)) {
            return null;
        }

        // Carries a response the thrower already built - Laravel returns it
        // verbatim, and reshaping it here would discard a deliberate reply.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                $e->getMessage(),
                $e->errors(),
            ),
            $e instanceof AuthenticationException => ApiResponse::error(ApiErrorCode::Unauthenticated),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(ApiErrorCode::Forbidden),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException        => ApiResponse::error(ApiErrorCode::ResourceNotFound),
            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                ApiErrorCode::RateLimited,
                headers: $e->getHeaders(),
            ),
            $e instanceof IdempotencyConflictException => ApiResponse::error(
                ApiErrorCode::IdempotencyConflict,
                $e->getMessage() !== '' ? $e->getMessage() : null,
            ),
            default => self::fallback($e),
        };
    }

    /**
     * Anything not recognised above.
     *
     * An HttpException carries a status the framework chose deliberately - 405
     * on a wrong verb, 413 on an oversized upload - so that status is kept and
     * only the body is reshaped. Everything else is a bug, and a bug is
     * reported as SERVER_ERROR with no detail: an exception message can carry a
     * query, a path or a provider response, none of which belongs on a phone.
     */
    private static function fallback(Throwable $e): JsonResponse
    {
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            // A 4xx message is framework-authored and safe to pass on. A 5xx
            // one is whatever the failing code happened to say, so it is
            // dropped for the same reason the generic branch below drops it.
            $message = $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : null;

            return ApiResponse::error(
                self::codeForStatus($status),
                $message,
                status: $status,
                headers: $e->getHeaders(),
            );
        }

        return ApiResponse::error(
            ApiErrorCode::ServerError,
            // Only ever in local debugging; production sees the generic line.
            config('app.debug') ? $e->getMessage() : null,
        );
    }

    private static function codeForStatus(int $status): ApiErrorCode
    {
        return match (true) {
            $status === 401 => ApiErrorCode::Unauthenticated,
            $status === 403 => ApiErrorCode::Forbidden,
            $status === 404 => ApiErrorCode::ResourceNotFound,
            $status === 409 => ApiErrorCode::IdempotencyConflict,
            $status === 422 => ApiErrorCode::ValidationFailed,
            $status === 426 => ApiErrorCode::UpgradeRequired,
            $status === 429 => ApiErrorCode::RateLimited,
            $status === 503 => ApiErrorCode::MaintenanceMode,
            $status >= 500  => ApiErrorCode::ServerError,
            default         => ApiErrorCode::Forbidden,
        };
    }
}
