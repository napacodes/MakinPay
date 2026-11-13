<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Exceptions\NotifyErrorException;
use App\Exceptions\WalletPinInvalidException;
use App\Exceptions\WalletPinRequiredException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checking the wallet PIN, with a counter behind it.
 *
 * Lifted out of WithdrawMoneyService, where it was the only PIN check on the
 * platform that counted attempts. A six-digit PIN is 10^6 guesses, which is
 * an afternoon for anything holding a valid token and nothing stopping it, so
 * the counter is the guard - the hash comparison on its own is not.
 *
 * ONE COUNTER, per user, for every flow that asks for the PIN.
 *
 * It used to be per SCOPE - `withdraw-pin`, `send-money-pin`, `agent-qr-pin`
 * and the merchant checkout's own `wallet-pin` - so the same six-digit secret
 * could be attacked five times on each of four screens. Twenty guesses, by
 * walking from one to the next. The limit capped attempts PER ACTION, and the
 * thing being guessed is not an action.
 *
 * The key is `wallet-pin:` because that is the one the merchant checkout was
 * already using, so its lockouts carry over. The other three keys are
 * abandoned, which gives anyone mid-lockout on those flows one fresh
 * allowance - a one-off, against a limit that never bound.
 *
 * The cost is real and was the operator's to weigh: a customer who mistypes
 * on two different screens now reaches the lockout sooner than before.
 */
final class WalletPinGuard
{
    /**
     * The one key. Named for the merchant checkout's, deliberately, so that
     * path's existing lockouts survive the merge.
     */
    private const RATE_KEY_PREFIX = 'wallet-pin:';

    /**
     * Demanded only from a customer who has set one, which is why the flows
     * that use this still work without a PIN.
     *
     * $incorrectMessage exists for one caller and is not a styling hook. The
     * QR cash-out screen is used with an agent present, where "the wallet PIN
     * is incorrect" does not say WHOSE - and that string already has
     * translations. A caller may rename the refusal; it may not opt out of
     * the counting, which is the point of this class.
     *
     * @throws NotifyErrorException
     */
    public function assert(User $user, ?string $pin, ?string $incorrectMessage = null, array $context = []): void
    {
        if (! $user->hasWalletPin()) {
            return;
        }

        if ($pin === null || $pin === '') {
            throw new WalletPinRequiredException;
        }

        $this->refuseWhileLockedOut($user, $context);

        if (! Hash::check($pin, (string) $user->wallet_pin)) {
            RateLimiter::hit($this->rateKey($user), $this->lockSeconds());

            Log::warning('Wallet PIN check failed', $context + ['user_id' => $user->id]);

            throw new WalletPinInvalidException($incorrectMessage);
        }

        RateLimiter::clear($this->rateKey($user));
    }

    /**
     * Confirm a change to the PIN with either the PIN itself or the account
     * password - on the same counter.
     *
     * The settings form that replaces the PIN used to check this with a bare
     * Hash::check: no counter, no lockout, no throttle on the route. So the
     * PIN could be walked there, all 10^6 of it, and the answer spent on send
     * money - the one screen that REPLACES the secret was the one that did not
     * count guesses at it.
     *
     * The password is accepted because that is how a customer who has
     * forgotten the PIN replaces it without the email. It spends the same
     * allowance when wrong, or the form turns into a password oracle instead.
     * And while locked out even the right answer is refused: a lockout that a
     * correct guess can end is a hint about which guess was correct.
     *
     * @throws NotifyErrorException
     */
    public function assertPinOrPassword(User $user, string $credential, array $context = []): void
    {
        $this->refuseWhileLockedOut($user, $context);

        $matches = ($user->hasWalletPin() && Hash::check($credential, (string) $user->wallet_pin))
            || Hash::check($credential, (string) $user->password);

        if (! $matches) {
            RateLimiter::hit($this->rateKey($user), $this->lockSeconds());

            Log::warning('Wallet PIN change refused', $context + ['user_id' => $user->id]);

            throw new NotifyErrorException(__('The current password or wallet PIN is incorrect.'));
        }

        RateLimiter::clear($this->rateKey($user));
    }

    /**
     * Seconds left on this customer's lockout; zero when there is none.
     *
     * Read-only, for a status screen. It spends nothing and clears nothing.
     */
    public function lockedFor(User $user): int
    {
        if (! RateLimiter::tooManyAttempts($this->rateKey($user), $this->maxAttempts())) {
            return 0;
        }

        return max(1, RateLimiter::availableIn($this->rateKey($user)));
    }

    /**
     * @throws NotifyErrorException
     */
    private function refuseWhileLockedOut(User $user, array $context): void
    {
        if (! RateLimiter::tooManyAttempts($this->rateKey($user), $this->maxAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->rateKey($user));

        /*
         * Logged for every flow. Only the merchant checkout kept a forensic
         * record before, which meant a PIN being ground down on withdraw left
         * no trace at all. The PIN itself never appears here - only who, from
         * where, and for how long.
         */
        Log::warning('Wallet PIN locked out', $context + [
            'user_id'     => $user->id,
            'retry_after' => $seconds,
        ]);

        throw new NotifyErrorException(__('Too many incorrect PIN attempts. Try again in :minutes minutes.', [
            'minutes' => (int) ceil($seconds / 60),
        ]));
    }

    private function rateKey(User $user): string
    {
        return self::RATE_KEY_PREFIX.$user->id;
    }

    private function maxAttempts(): int
    {
        return max(3, min(10, (int) config('security.wallet_pin_attempt_limit', 5)));
    }

    private function lockSeconds(): int
    {
        return max(1, min(60, (int) config('security.wallet_pin_lock_minutes', 15))) * 60;
    }
}
