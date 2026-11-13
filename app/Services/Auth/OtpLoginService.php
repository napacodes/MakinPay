<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\PhoneVerification\SendsSignInCodes;
use App\Exceptions\NotifyErrorException;
use App\Models\LoginOtpCode;
use App\Models\User;
use App\Services\PhoneVerification\PhoneVerificationProviderManager;
use App\Support\HomeCountry;
use App\Support\PhoneIdentifier;
use App\Support\SignInThrottle;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Passwordless sign-in by a one-time code sent by SMS (GF9).
 *
 * Off unless the administrator switches it on (`otp_login_enabled`, layer C),
 * and unavailable while the SMS provider is the log driver: that driver
 * writes the code into the log, and a code that can be read from a log file
 * is a key to every account. So no code this service issues is ever logged.
 *
 * ASKING FOR A CODE SAYS NOTHING ABOUT WHO EXISTS. Every request is answered
 * the same way and counted against the same limits - one per minute and three
 * per fifteen minutes for a number, ten an hour for an address - whether or
 * not the number belongs to an account, so the endpoint cannot be used to
 * find customers. A code is sent only when the number names exactly one
 * account, that account's phone is verified, and it is not a system account.
 * A number that could mean two accounts is not guessed between: nobody gets
 * a code. The limits are kept per phone the typed string could mean, so one
 * number has one budget however it is spelled; and the SMS goes out after
 * the answer, with a decoy hash on the no-account path, so the time taken
 * says no more than the answer does.
 *
 * USING A CODE is a sign-in attempt like a password: a wrong code counts
 * towards the same lockout (SignInThrottle, shared with the password forms),
 * a code takes at most `otp_login_max_attempts` tries - each reserved in one
 * conditional update before the comparison, so parallel guesses cannot share
 * a count - it expires after `otp_login_expires_minutes`, a wrong code is
 * recorded as a Failed sign-in for the risk panel, and it works once
 * - the claim that consumes it is a conditional update, so two requests with
 * the same code cannot both sign in.
 *
 * What happens after a correct code - suspension, email verification, the
 * second factor - is the caller's, exactly as after a correct password.
 */
class OtpLoginService
{
    private const REQUESTS_PER_NUMBER = 3;

    private const NUMBER_WINDOW_SECONDS = 900;

    private const REQUESTS_PER_ADDRESS = 10;

    private const ADDRESS_WINDOW_SECONDS = 3600;

    private const COOLDOWN_SECONDS = 60;

    private static ?string $decoy = null;

    public function __construct(private readonly PhoneVerificationProviderManager $providers) {}

    /**
     * Whether a customer can be offered sign-in by SMS code on this site.
     */
    public function isAvailable(): bool
    {
        return (bool) setting('otp_login_enabled', false)
            && (string) config('mobile_services.phone_verification.provider', 'log') !== 'log';
    }

    /**
     * Send a sign-in code to the account this number names, if there is
     * exactly one. Answers the same whether or not there is.
     *
     * @throws NotifyErrorException when the feature is off, the number cannot be read, or a limit is reached
     */
    public function request(string $login, string $ip): void
    {
        $this->assertAvailable();

        $candidates = $this->candidates($login);

        // Keyed on every phone the typed string could mean, so a number has
        // one budget however it is spelled: '1712345678' under a Bangladesh
        // home draws on +8801712345678's limits as well as +1712345678's.
        $limits = [['otp-login:address:'.$ip, self::REQUESTS_PER_ADDRESS, self::ADDRESS_WINDOW_SECONDS]];

        foreach ($candidates as $phone) {
            $limits[] = ['otp-login:cooldown:'.sha1($phone), 1, self::COOLDOWN_SECONDS];
            $limits[] = ['otp-login:number:'.sha1($phone), self::REQUESTS_PER_NUMBER, self::NUMBER_WINDOW_SECONDS];
        }

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new NotifyErrorException(__('Please wait before requesting another code. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]), 429);
            }
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $user = $this->resolve($candidates);

        if ($user === null) {
            // The same hashing work a real code costs, so a quick answer does
            // not tell a number with no account from one with an account.
            Hash::make($this->generateCode());

            return;
        }

        $code    = $this->generateCode();
        $minutes = $this->expiresMinutes();

        $record = DB::transaction(function () use ($user, $code, $minutes, $ip): LoginOtpCode {
            // Only the newest code works.
            LoginOtpCode::query()->where('user_id', $user->id)->live()->update(['consumed_at' => now()]);

            return LoginOtpCode::query()->create([
                'user_id'      => $user->id,
                'phone_number' => (string) $user->phone,
                'code_hash'    => Hash::make($code),
                'attempts'     => 0,
                'expires_at'   => now()->addMinutes($minutes),
                'requested_ip' => $ip,
            ]);
        });

        /*
         * Sent once the answer has gone, so an SMS gateway's round trip does
         * not make a number with an account answer slower than one without.
         * A terminating callback, not a job - not even an after-response one,
         * which goes through the sync queue's serializer - so the code is
         * never written into any payload.
         */
        // Once: an application that serves more than one request (Octane, a
        // test) keeps its terminating callbacks and runs them every time.
        $delivered = false;

        app()->terminating(function () use (&$delivered, $user, $code, $minutes, $record): void {
            if ($delivered) {
                return;
            }

            $delivered = true;
            $this->deliver($user, $code, $minutes, $record);
        });
    }

    private function deliver(User $user, string $code, int $minutes, LoginOtpCode $record): void
    {
        try {
            $provider = $this->providers->activeProvider();

            $provider instanceof SendsSignInCodes
                ? $provider->sendSignInCode($user, $code, $minutes)
                : $provider->send($user, $code);
        } catch (Throwable $exception) {
            // Nothing arrived, so nothing should work. The exception carries
            // no code - the provider was handed it, not the message.
            $record->delete();
            report($exception);
        }
    }

    /**
     * The account a correct, live code signs in, or null. Never says which
     * part was wrong.
     *
     * @param list<string> $throttleKeys the password sign-in's keys for this login and address (SignInThrottle::keys)
     *
     * @throws NotifyErrorException when the feature is off or the lockout applies
     */
    public function verify(string $login, string $code, array $throttleKeys, string $ip): ?User
    {
        $this->assertAvailable();

        if (SignInThrottle::tooManyAttempts($throttleKeys, $this->attemptLimit())) {
            $seconds = SignInThrottle::availableIn($throttleKeys, $this->attemptLimit());

            throw new NotifyErrorException(trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]), 429);
        }

        $user = $this->resolve($this->candidates($login));
        $live = $user === null ? null : LoginOtpCode::query()
            ->where('user_id', $user->id)
            ->where('phone_number', (string) $user->phone)
            ->live()
            ->latest('id')
            ->first();

        if ($user === null || $live === null || ! preg_match('/\A\d{4,10}\z/', trim($code))) {
            // The same hashing work a real check costs, so the answer's
            // timing does not say whether the number has a live code.
            Hash::check(trim($code), $this->decoyHash());
            SignInThrottle::hit($throttleKeys, $this->lockSeconds());

            if ($user !== null) {
                event(new Failed('web', $user, ['phone' => (string) $user->phone]));
            }

            return null;
        }

        /*
         * The attempt is reserved before the code is compared, in one
         * conditional update: concurrent guesses each take their own
         * attempt, so a burst from many addresses cannot share one count and
         * get more tries than the cap. A code whose tries are used up is
         * refused without being compared at all.
         */
        $reserved = LoginOtpCode::query()
            ->whereKey($live->id)
            ->live()
            ->where('attempts', '<', $this->maxAttempts())
            ->increment('attempts');

        if ($reserved !== 1) {
            Hash::check(trim($code), $this->decoyHash());
            SignInThrottle::hit($throttleKeys, $this->lockSeconds());
            event(new Failed('web', $user, ['phone' => (string) $user->phone]));

            return null;
        }

        if (! Hash::check(trim($code), $live->code_hash)) {
            SignInThrottle::hit($throttleKeys, $this->lockSeconds());
            event(new Failed('web', $user, ['phone' => (string) $user->phone]));

            // The last allowed try used: the code is spent.
            LoginOtpCode::query()
                ->whereKey($live->id)
                ->whereNull('consumed_at')
                ->where('attempts', '>=', $this->maxAttempts())
                ->update(['consumed_at' => now()]);

            return null;
        }

        // One sign-in per code: the second of two simultaneous requests finds
        // it already consumed and is refused.
        $claimed = LoginOtpCode::query()->whereKey($live->id)->live()->update(['consumed_at' => now()]);

        if ($claimed !== 1) {
            SignInThrottle::hit($throttleKeys, $this->lockSeconds());

            return null;
        }

        SignInThrottle::clearAfterSignIn($throttleKeys, $user, $ip);

        return $user;
    }

    /**
     * @return list<string>
     *
     * @throws NotifyErrorException when the input is not a phone number
     */
    private function candidates(string $login): array
    {
        $candidates = PhoneIdentifier::candidates(trim($login), HomeCountry::code());

        if ($candidates === []) {
            throw new NotifyErrorException(__('Enter the phone number on your account.'));
        }

        return $candidates;
    }

    /**
     * The one ordinary account with a verified phone the number names, or
     * null - including when it could name two.
     *
     * @param list<string> $candidates
     */
    private function resolve(array $candidates): ?User
    {
        $matches = User::query()
            ->whereIn('phone', $candidates)
            ->whereNull('system_account_type')
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();

        return $user->phone_verified_at === null ? null : $user;
    }

    /**
     * A hash at the configured cost to compare against when there is no real
     * one, made once per process.
     */
    private function decoyHash(): string
    {
        return self::$decoy ??= Hash::make(Str::random(32));
    }

    /**
     * @throws NotifyErrorException
     */
    private function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new NotifyErrorException(__('Sign-in by SMS code is not available.'), 404);
        }
    }

    private function generateCode(): string
    {
        $testingCode = (string) config('mobile_services.phone_verification.testing_code', '');

        if (app()->environment('testing') && $testingCode !== '') {
            return $testingCode;
        }

        return Str::padLeft((string) random_int(0, 999999), 6, '0');
    }

    private function expiresMinutes(): int
    {
        return max(1, min(15, (int) setting('otp_login_expires_minutes', 5)));
    }

    private function maxAttempts(): int
    {
        return max(1, min(10, (int) setting('otp_login_max_attempts', 5)));
    }

    private function attemptLimit(): int
    {
        return max(3, min(10, (int) config('security.login_attempt_limit', 5)));
    }

    private function lockSeconds(): int
    {
        return max(1, min(60, (int) config('security.login_lock_minutes', 15))) * 60;
    }
}
