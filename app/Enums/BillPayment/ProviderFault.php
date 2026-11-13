<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * A refusal that is the provider account's fault, not the payment's (BP1).
 *
 * Every following payment would be refused the same way, so a fault of
 * this kind pauses the provider: no new payments are taken for its billers
 * until an administrator tests the connection again and it passes. The
 * payment that hit it is still a plain Failed and is refunded as before.
 */
enum ProviderFault: string
{
    /** The provider's prepaid balance cannot cover the bill. */
    case InsufficientBalance = 'insufficient_balance';

    /** The provider refused the platform's credentials. */
    case Credentials = 'credentials';

    /**
     * The fault a refusal names, or null for a refusal about the payment.
     *
     * Read from the HTTP status, the provider's error code and its message,
     * because the providers' exact codes for an empty balance are still on
     * their live-check lists: matched loosely on the words, never guessed
     * from a bare 4xx.
     */
    public static function fromRefusal(?int $httpStatus, ?string $errorCode, ?string $message): ?self
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return self::Credentials;
        }

        $said = strtolower(trim(($errorCode ?? '').' '.($message ?? '')));

        if (preg_match('/insufficient[\s_-]*(?:account[\s_-]*)?(?:balance|funds?|wallet)|low[\s_-]*balance|balance[\s_-]*(?:is[\s_-]*)?(?:too[\s_-]*)?low/', $said) === 1) {
            return self::InsufficientBalance;
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::InsufficientBalance => __('Provider balance too low'),
            self::Credentials         => __('Provider refused the credentials'),
        };
    }
}
