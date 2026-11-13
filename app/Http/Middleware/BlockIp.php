<?php

namespace App\Http\Middleware;

use App\Models\IPBlock;
use App\Services\Security\IpAbuseGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuses the addresses on the deny list, whether an administrator wrote
 * the row or {@see IpAbuseGuard} did.
 *
 * The list is cached because this runs on every request. It is cached as a
 * map of address to expiry rather than a flat list so an automatic block
 * lapses at its own minute instead of at the next cache miss; writers on
 * both sides forget the key so a new block bites immediately.
 */
class BlockIp
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Demo mode refuses no address, including ones already on the list.
         * The rows are left alone, so they apply again once demo mode is off.
         */
        if ((bool) config('app.demo')) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        $blocked = $this->deniedAddresses();

        if (! array_key_exists($ip, $blocked)) {
            return $next($request);
        }

        $expiresAt = $blocked[$ip];

        if ($expiresAt !== null && $expiresAt <= time()) {
            return $next($request);
        }

        abort(403, 'Your IP is blocked.');
    }

    /**
     * Address => expiry timestamp (null = until an admin removes it).
     *
     * @return array<string, int|null>
     */
    private function deniedAddresses(): array
    {
        try {
            return Cache::remember(IpAbuseGuard::CACHE_KEY, now()->addMinutes(5), function (): array {
                return IPBlock::query()
                    ->active()
                    ->get(['ip_address', 'expires_at'])
                    ->mapWithKeys(fn (IPBlock $block): array => [
                        (string) $block->ip_address => $block->expires_at?->getTimestamp(),
                    ])
                    ->all();
            });
        } catch (Throwable) {
            /*
             * A deny list that cannot be read must not take the site down
             * with it — the request is allowed through and the next one
             * tries again.
             */
            return [];
        }
    }
}
