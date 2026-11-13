<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\NotifyErrorException;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * A six-digit code that proves the address, so sign-up can finish inside
 * the app instead of leaving for a mail client.
 *
 * The link Laravel already mails keeps working; this runs beside it. Only a
 * hash of the code is kept, in the cache, against the account it was sent
 * to, with an expiry and an attempt budget - the same shape as the phone
 * code, which has been verifying numbers this way since the app existed.
 */
class EmailVerificationCodeService
{
    private const EXPIRES_MINUTES = 15;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    /**
     * Mail a fresh code. A second ask inside the cooldown is refused with the
     * seconds left, so a tapped-twice button does not send two codes.
     *
     * @throws NotifyErrorException
     */
    public function send(User $user): void
    {
        $record = Cache::get($this->key($user));

        if (is_array($record) && ($record['sent_at'] ?? 0) > time() - self::RESEND_COOLDOWN_SECONDS) {
            $wait = self::RESEND_COOLDOWN_SECONDS - (time() - (int) $record['sent_at']);

            throw new NotifyErrorException(__('Please wait :seconds seconds before asking for another code.', ['seconds' => $wait]));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($user), [
            'hash'     => Hash::make($code),
            'attempts' => 0,
            'sent_at'  => time(),
        ], now()->addMinutes(self::EXPIRES_MINUTES));

        $user->notify(new EmailVerificationCodeNotification($code, self::EXPIRES_MINUTES));
    }

    /**
     * Check a code and, when it is right, mark the address verified.
     *
     * @return int|null attempts left after a wrong code, null when the code
     *                  was right or there is nothing to check against
     *
     * @throws NotifyErrorException on a wrong, spent or missing code
     */
    public function verify(User $user, string $code): void
    {
        $record = Cache::get($this->key($user));

        if (! is_array($record)) {
            throw new NotifyErrorException(__('That code has expired. Please ask for a new one.'));
        }

        if ((int) $record['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($this->key($user));

            throw new NotifyErrorException(__('Too many wrong codes. Please ask for a new one.'));
        }

        if (! Hash::check(trim($code), (string) $record['hash'])) {
            $record['attempts'] = (int) $record['attempts'] + 1;
            Cache::put($this->key($user), $record, now()->addMinutes(self::EXPIRES_MINUTES));

            $left = self::MAX_ATTEMPTS - $record['attempts'];

            throw new WrongEmailCodeException(
                __('That code is not right. :count attempts left.', ['count' => $left]),
                $left,
            );
        }

        Cache::forget($this->key($user));

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }

    private function key(User $user): string
    {
        return 'email-verification-code:'.$user->getKey();
    }
}
