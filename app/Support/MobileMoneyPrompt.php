<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\NotifyErrorException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * The prompt a mobile-money gateway sends to the payer's phone.
 *
 * A prompt costs its recipient something - a USSD screen that interrupts
 * whatever they were doing - and anyone can type anyone's number into a
 * public checkout. So prompts are limited per number and per address, and
 * the limit is checked before a pending row exists, not after.
 */
final class MobileMoneyPrompt
{
    public const int PROMPTS_PER_NUMBER = 5;

    public const int PROMPTS_PER_ADDRESS = 20;

    public const int WINDOW_SECONDS = 900;

    /**
     * How long the "approve on your phone" link keeps working. A prompt
     * expires within minutes; the link outlives it so a payer who comes back
     * still learns how it ended.
     */
    public const int LINK_MINUTES = 120;

    public static function waitingUrl(string $trxId): string
    {
        return URL::temporarySignedRoute('payment.mobile-money.wait', now()->addMinutes(self::LINK_MINUTES), ['trx' => $trxId]);
    }

    public static function statusUrl(string $trxId): string
    {
        return URL::temporarySignedRoute('payment.mobile-money.status', now()->addMinutes(self::LINK_MINUTES), ['trx' => $trxId]);
    }

    /**
     * Refuse a prompt to a number, or from an address, that has had enough.
     * Checking only - recordPrompt() counts one once it is really sent.
     *
     * @throws NotifyErrorException
     */
    public static function assertMayPrompt(string $phone): void
    {
        foreach (self::limits($phone) as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw new NotifyErrorException(__('Too many payment requests were sent to this number. Try again in :minutes minutes.', [
                    'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                ]));
            }
        }
    }

    public static function recordPrompt(string $phone): void
    {
        foreach (array_keys(self::limits($phone)) as $key) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }
    }

    /**
     * The number as the payer can recognise it without it being shown to
     * whoever is looking at their screen: the last three digits.
     */
    public static function maskedPhone(mixed $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? '' : '•••• '.substr($digits, -3);
    }

    /**
     * Keyed by the subscriber digits rather than the typed form, so '+256 77…'
     * and '077…' count as the same handset, and hashed so the cache never
     * holds a phone number.
     *
     * @return array<string, int>
     */
    private static function limits(string $phone): array
    {
        $digits     = preg_replace('/\D+/', '', $phone) ?? '';
        $subscriber = substr($digits, -9);

        return [
            'mobile-money-prompt:number:'.hash('sha256', $subscriber)               => self::PROMPTS_PER_NUMBER,
            'mobile-money-prompt:address:'.hash('sha256', (string) request()->ip()) => self::PROMPTS_PER_ADDRESS,
        ];
    }
}
