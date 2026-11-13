<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The sign-in rate limit, shared by the web form and the mobile API.
 *
 * Two kinds of key, counted together. The LOGIN key is the typed string and
 * the IP, exactly as it always was, so an email or a username keeps its
 * budget untouched. Next to it there is one PHONE key per number the sign-in
 * actually checks the password against - PhoneIdentifier::candidates() - so
 * every way of typing one number lands on one budget: with or without the
 * trunk zero, spaced, with its +code, or with a stray character the reading
 * drops ('+8801712345678#'). Keying on the shape of the typed string instead
 * left exactly that open: each new spelling bought a fresh five tries at the
 * same account.
 *
 * A sign-in is turned away while ANY of its keys is spent, a refusal counts
 * against all of them, and a success clears only the login key and the phone
 * that signed in. Clearing every phone the typing could mean would let
 * somebody reset the count on a stranger's number by signing into their own
 * account with a bare number both readings share.
 */
final class SignInThrottle
{
    /**
     * Every key this typing counts against: the login key first.
     *
     * @return list<string>
     */
    public static function keys(string $loginKey, string $login, ?string $homeCountry, string $ip): array
    {
        $keys = [$loginKey];

        foreach (PhoneIdentifier::candidates($login, $homeCountry) as $phone) {
            $keys[] = self::phoneKey($phone, $ip);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param list<string> $keys
     */
    public static function tooManyAttempts(array $keys, int $limit): bool
    {
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seconds until every spent key is free again.
     *
     * @param list<string> $keys
     */
    public static function availableIn(array $keys, int $limit): int
    {
        $seconds = 0;

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        return $seconds;
    }

    /**
     * Count one refused submission against every key, once each.
     *
     * @param list<string> $keys
     */
    public static function hit(array $keys, int $decaySeconds): void
    {
        foreach ($keys as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    /**
     * The most attempts spent on any key: what "tries left" is measured
     * against. It depends only on what was typed, never on whether an account
     * exists, so it tells an unknown number and a wrong password apart no
     * better than the message does.
     *
     * @param list<string> $keys
     */
    public static function attempts(array $keys): int
    {
        $attempts = 0;

        foreach ($keys as $key) {
            $attempts = max($attempts, (int) RateLimiter::attempts($key));
        }

        return $attempts;
    }

    /**
     * After a right password: clear the login key, and the phone key of the
     * account that signed in - never another reading's.
     *
     * @param list<string> $keys
     */
    public static function clearAfterSignIn(array $keys, ?User $user, string $ip): void
    {
        RateLimiter::clear($keys[0]);

        $phone = trim((string) $user?->phone);

        if ($phone === '') {
            return;
        }

        $phoneKey = self::phoneKey($phone, $ip);

        if (in_array($phoneKey, $keys, true)) {
            RateLimiter::clear($phoneKey);
        }
    }

    private static function phoneKey(string $phone, string $ip): string
    {
        return 'phone:'.$phone.'|'.$ip;
    }
}
