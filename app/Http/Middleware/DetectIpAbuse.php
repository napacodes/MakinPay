<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\IpAbuseGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns refused responses into strikes against the address that caused them.
 *
 * Reading the status the application already decided on is deliberate: the
 * guard then reacts to every refusal in the codebase — a policy denial, a
 * missing CSRF token, a rate limiter, an API token that is not valid —
 * without any of those places having to know this exists, and without it
 * inventing a judgement of its own about what a "hack attempt" looks like.
 *
 * Runs outside the rest of the stack but inside {@see BlockIp}, so an
 * address that is already blocked does not keep striking itself and
 * extending its own block.
 */
class DetectIpAbuse
{
    /**
     * Statuses that mean the application turned this visitor away.
     *
     * 404 is deliberately absent: a dead link is the most common status on
     * any site and counting it would block people for following one. 422
     * and the validation redirect are absent for the same reason — a wrong
     * form field is a customer, not an intruder.
     *
     * @var array<int, string>
     */
    public const SIGNALS = [
        401 => 'unauthenticated',
        403 => 'forbidden',
        419 => 'csrf',
        429 => 'throttled',
    ];

    public function __construct(private readonly IpAbuseGuard $guard) {}

    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $signal = self::SIGNALS[$response->getStatusCode()] ?? null;

        if ($signal !== null) {
            $this->guard->record($request, $signal);
        }

        return $response;
    }
}
