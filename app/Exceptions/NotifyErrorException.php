<?php

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use App\Http\Middleware\UseApiErrorEnvelope;
use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotifyErrorException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     *
     * A browser gets the message as a flash and a redirect back. An API
     * caller gets it as JSON: the redirect used to reach integrators as a
     * 302 to a page they could not parse, with the reason flashed into a
     * session that does not exist on the API. The exception's own code is the
     * HTTP status when it is one (the feature gate throws 403), and the
     * message is otherwise a refused request rather than a server fault.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        /*
         * The mobile API answers in its own envelope so the app can branch
         * on a stable code. Only routes that opted in through
         * UseApiErrorEnvelope take this path; the shape below is what every
         * existing integration already parses and is left exactly as it is.
         *
         * This lives here rather than in ApiExceptionRenderer because
         * Handler::render consults an exception s own render() before any
         * registered callback, so the renderer never sees this one.
         */
        if (UseApiErrorEnvelope::wants($request)) {
            $status = $this->httpStatus();

            return ApiResponse::error(
                $this->apiErrorCode($status),
                $this->getMessage(),
                status: $status,
            );
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            // Same status, deliberately without the code: this is the shape
            // the existing integrations parse and adding a field to it is a
            // change none of them asked for.
            return new JsonResponse([
                'status'  => false,
                'message' => $this->getMessage(),
            ], $this->httpStatus());
        }

        // Use a notification service or session flashing
        notifyEvs('error', $this->getMessage());

        // Redirect back with the notification
        return redirect()->back();
    }

    /**
     * The status this refusal carries, or 422 when it carries none.
     *
     * The exception's own code doubles as an HTTP status where a thrower set
     * one - the feature gate throws 403 - and is 0 otherwise.
     */
    private function httpStatus(): int
    {
        $code = (int) $this->getCode();

        return $code >= 400 && $code < 600 ? $code : 422;
    }

    /**
     * The stable code the app branches on.
     *
     * Every refusal used to report VALIDATION_FAILED whatever its status, so
     * a 403 from the per-user feature gate and a 404 from a missing resource
     * arrived indistinguishable from a bad field. The status was right and
     * the code - the thing an app is supposed to switch on - was not, which
     * made the envelope's central promise untrue for most of the money
     * surface.
     *
     * Mapped from the status rather than guessed from the message, because
     * the message is translated and the status is not. A subclass that IS a
     * specific refusal (a short balance, say) overrides this to name it,
     * since a status alone cannot tell two different 409s apart.
     */
    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return match ($status) {
            401     => ApiErrorCode::Unauthenticated,
            403     => ApiErrorCode::Forbidden,
            404     => ApiErrorCode::ResourceNotFound,
            409     => ApiErrorCode::IdempotencyConflict,
            429     => ApiErrorCode::RateLimited,
            default => ApiErrorCode::ValidationFailed,
        };
    }
}
