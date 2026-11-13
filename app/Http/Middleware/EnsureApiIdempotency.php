<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Api\ApiErrorCode;
use App\Models\ApiIdempotencyKey;
use App\Services\Api\IdempotencyGuard;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a money-moving request safe to send twice.
 *
 * A phone on a train sends a cash-in, loses the reply, and sends it again.
 * Without a record of the first attempt the second is indistinguishable from a
 * second cash-in. The header is therefore required rather than optional here:
 * an endpoint that moves money and lets a client opt out of replay protection
 * is one bad connection away from a double credit.
 *
 * A repeat of the same key with the same body is answered with exactly what
 * the first request produced, so the app cannot tell a replay from the
 * original and does not need to. The same key with a DIFFERENT body is
 * refused, not replayed: that is a client bug or an attack, and answering it
 * with the earlier result would silently discard a transfer somebody meant to
 * make.
 *
 * A failed attempt releases its key, so a customer whose transfer was refused
 * for insufficient funds can top up and retry with the same key rather than
 * being told they already tried.
 *
 * THE MECHANICS ARE SHARED with EnsureMerchantApiIdempotency through
 * IdempotencyGuard - the key syntax, the request fingerprint, and the race
 * where two requests arrive together and the unique index settles it. What
 * stays here is the POLICY, all of which differs from the merchant API's: the
 * key is required rather than optional, refusals wear the mobile envelope, a
 * crashed request gives its key back, and the replay header is spelt the way
 * this API's clients already read it.
 */
class EnsureApiIdempotency
{
    private const WINDOW_HOURS = 24;

    public function __construct(private readonly IdempotencyGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated);
        }

        $key = $this->guard->keyFrom($request);

        if ($key === '') {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                __('An Idempotency-Key header is required for this request.'),
                ['idempotency_key' => [__('An Idempotency-Key header is required for this request.')]],
            );
        }

        if (! $this->guard->isWellFormed($key)) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                __('Idempotency-Key must contain 1 to 255 letters, numbers, dots, underscores, colons, or hyphens.'),
            );
        }

        $hash = $this->guard->fingerprint($request);

        [$record, $claimed] = $this->guard->claim(
            ApiIdempotencyKey::class,
            ['user_id' => $user->id],
            $key,
            $hash,
            self::WINDOW_HOURS,
        );

        // Somebody already holds this key: an earlier request, or the winner
        // of a race this one just lost.
        if (! $claimed) {
            return $this->replayOrRefuse($record, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // The key must not outlive a request that never produced an
            // answer, or a retry of a crashed call is refused forever.
            $this->guard->release($record);

            throw $exception;
        }

        if ($this->worthReplaying($response)) {
            $this->guard->remember($record, $response);
        } else {
            $this->guard->release($record);
        }

        return $response;
    }

    private function replayOrRefuse(ApiIdempotencyKey $record, string $hash): Response
    {
        if (! $this->guard->matches($record, $hash)) {
            return ApiResponse::error(
                ApiErrorCode::IdempotencyConflict,
                __('This Idempotency-Key was already used with different request parameters.'),
            );
        }

        if (! $record->isReplayable()) {
            // The first attempt is still in flight, or its window has closed.
            // Either way there is nothing to replay and nothing safe to do but
            // refuse, because proceeding could run the operation twice.
            return ApiResponse::error(
                ApiErrorCode::IdempotencyConflict,
                __('A request with this Idempotency-Key is already being processed.'),
            );
        }

        return response(
            (string) $record->response_body,
            (int) $record->response_status,
        )->withHeaders([
            'Content-Type'         => $record->response_content_type ?? 'application/json',
            'Idempotency-Replayed' => 'true',
        ]);
    }

    /**
     * Only a settled outcome is worth storing.
     *
     * A refusal the caller can correct - bad input, insufficient balance - is
     * not stored, so the same key works again once they have fixed it. A
     * server fault is not stored either: nobody knows whether the money moved,
     * and replaying an unknown is worse than repeating the question.
     */
    private function worthReplaying(Response $response): bool
    {
        $status = $response->getStatusCode();

        return $status < 400 || $status === 409;
    }
}
