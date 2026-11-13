<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The short-lived ticket that stands between a correct password and an access
 * token on an account holding a second factor.
 *
 * The web panel does this with a session flag. A token client has no session,
 * so login hands back a challenge instead of a token and the app exchanges it
 * for one by proving the second factor.
 *
 * It lives in the cache rather than in personal_access_tokens on purpose. A
 * row there is a credential: anything that lists a customer's tokens, revokes
 * them, or checks abilities would have to learn that some of them are not
 * usable yet. A cache entry expires by itself and can authorise nothing.
 *
 * The token is never the cache key. Its SHA-256 is, so a dump of the cache
 * yields nothing anyone can present.
 */
final class TwoFactorChallengeService
{
    private const PREFIX = 'api:2fa-challenge:';

    /**
     * Long enough for a customer to open their authenticator and read a code,
     * short enough that a challenge left on a screen is not a standing key.
     */
    public const TTL_SECONDS = 300;

    /**
     * Wrong codes allowed before the challenge is destroyed and the customer
     * has to start from the password again.
     */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * @param  array{id?: string|null, name?: string|null, platform?: string|null, app_version?: string|null} $device
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user, array $device = []): array
    {
        $token = Str::random(64);

        Cache::put($this->key($token), [
            'user_id'  => $user->id,
            'device'   => $device,
            'attempts' => 0,
            // Absolute, so a re-put after a wrong code can restore what was
            // left rather than starting the clock again.
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ], self::TTL_SECONDS);

        return ['token' => $token, 'expires_in' => self::TTL_SECONDS];
    }

    /**
     * Trade a challenge and a code for the user it belongs to.
     *
     * Returns null for every failure - unknown, expired, already spent, wrong
     * code, out of attempts - because the app has nothing useful to do with
     * the difference and an attacker would.
     *
     * @return array{user: User, device: array<string, mixed>}|null
     */
    public function consume(?string $challengeToken, string $code): ?array
    {
        if ($challengeToken === null || $challengeToken === '') {
            return null;
        }

        $key   = $this->key($challengeToken);
        $entry = Cache::get($key);

        if (! is_array($entry)) {
            return null;
        }

        $user = User::find($entry['user_id'] ?? null);

        // The account was deleted, or had its second factor turned off, while
        // the challenge was open.
        if (! $user instanceof User || ! $user->two_factor_enabled || blank($user->google2fa_secret)) {
            Cache::forget($key);

            return null;
        }

        if (! $this->twoFactor->verifyCode((string) $user->google2fa_secret, $code)) {
            $this->recordFailure($key, $entry);

            return null;
        }

        // Single use. Spent the moment it works, so a code read over someone's
        // shoulder cannot be replayed against the same challenge.
        Cache::forget($key);

        return ['user' => $user, 'device' => is_array($entry['device'] ?? null) ? $entry['device'] : []];
    }

    /**
     * Count a wrong code, and destroy the challenge once the budget is spent.
     *
     * Destroying rather than locking: there is nothing to unlock, and the
     * customer still holds the password that produced this challenge, so
     * starting again costs them one sign-in and costs an attacker everything.
     *
     * @param array<string, mixed> $entry
     */
    private function recordFailure(string $key, array $entry): void
    {
        $attempts = (int) ($entry['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return;
        }

        $entry['attempts'] = $attempts;

        // The remaining lifetime, never a fresh one: re-putting the full TTL
        // would let a guesser hold one challenge open for as long as they
        // kept being wrong.
        $remaining = (int) ($entry['expires_at'] ?? 0) - now()->getTimestamp();

        if ($remaining <= 0) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, $entry, $remaining);
    }

    private function key(string $token): string
    {
        return self::PREFIX.hash('sha256', $token);
    }
}
