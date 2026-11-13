<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Models\MerchantApiIdempotency;
use App\Services\Api\IdempotencyGuard;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Replay protection for the server-to-server merchant API.
 *
 * THE MECHANICS ARE SHARED with EnsureApiIdempotency through IdempotencyGuard
 * - the key syntax, the request fingerprint, and the race where two requests
 * arrive together and the unique index settles it. Nothing about this
 * middleware's behaviour changed when they were folded together; what stays
 * here is every policy decision, and each one differs from the mobile API's:
 *
 *   - a missing key is ALLOWED and simply skips the guard. That is what this
 *     API's integrators have always had, and taking it away would break every
 *     one of them at once.
 *   - refusals answer `{error, message}`, which is this API's shape.
 *   - a 5xx IS stored, so a retry cannot re-run an operation whose outcome
 *     nobody knows. The mobile API does the opposite on purpose: a customer
 *     whose transfer crashed should be able to try again.
 *   - a thrown request becomes a 500 here rather than propagating.
 *   - the replay header is `Idempotent-Replayed`, which is not how the mobile
 *     API spells it. Neither can be renamed without breaking a client that
 *     reads it.
 */
class EnsureMerchantApiIdempotency
{
    public function __construct(private readonly IdempotencyGuard $guard) {}

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->guard->keyFrom($request);

        // Opt-in for this API, unlike the mobile one.
        if ($key === '') {
            return $next($request);
        }

        if (! $this->guard->isWellFormed($key)) {
            return $this->error(
                code: 'invalid_idempotency_key',
                message: __('Idempotency-Key must contain 1 to 255 letters, numbers, dots, underscores, colons, or hyphens.'),
                status: 400,
            );
        }

        /** @var Merchant $merchant */
        $merchant    = $request->get('merchant');
        $environment = (string) $request->get('environment');
        $requestHash = $this->guard->fingerprint($request);

        [$record, $claimed] = $this->guard->claim(
            MerchantApiIdempotency::class,
            ['merchant_id' => $merchant->id, 'environment' => $environment],
            $key,
            $requestHash,
            24,
        );

        if (! $claimed) {
            return $this->replayOrConflict($record, $requestHash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            report($exception);
            $response = $this->error(
                code: 'internal_error',
                message: __('The request could not be completed.'),
                status: 500,
            );
        }

        if ($this->shouldPersist($response)) {
            $this->guard->remember($record, $response);
        } else {
            $this->guard->release($record);
        }

        return $response;
    }

    private function replayOrConflict(MerchantApiIdempotency $record, string $requestHash): Response
    {
        if (! $this->guard->matches($record, $requestHash)) {
            return $this->error(
                code: 'idempotency_key_reused',
                message: __('This Idempotency-Key was already used with different request parameters.'),
                status: 409,
            );
        }

        if ($record->response_status === null) {
            return $this->error(
                code: 'idempotency_request_in_progress',
                message: __('A request with this Idempotency-Key is still being processed. Retry shortly.'),
                status: 409,
            )->withHeaders(['Retry-After' => '2']);
        }

        return response($record->response_body, $record->response_status)
            ->header('Content-Type', $record->response_content_type ?: 'application/json')
            ->header('Idempotent-Replayed', 'true')
            ->header('Cache-Control', 'no-store');
    }

    private function shouldPersist(Response $response): bool
    {
        $status = $response->getStatusCode();

        return ($status >= 200 && $status < 300) || $status === 409 || $status >= 500;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error'   => $code,
            'message' => $message,
        ], $status)->withHeaders(['Cache-Control' => 'no-store']);
    }
}
