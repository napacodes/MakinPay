<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use InvalidArgumentException;

/**
 * What an authenticated provider callback says, reduced to which payment it
 * is about. It deliberately carries no status, amount or currency: a webhook
 * only triggers a status check, and only the check's answer is applied.
 *
 *   externalEventId        stable for one provider event, so a resent
 *                          callback is recognised and dropped
 *   providerRequestId      our own reference, as echoed by the provider
 *   providerTransactionId  the provider's reference for the payment
 *
 * At least one reference is required: a driver returns null instead of
 * building a notice that names no payment.
 */
readonly class BillPaymentWebhookNotice
{
    public function __construct(
        public string $externalEventId,
        public ?string $providerRequestId = null,
        public ?string $providerTransactionId = null,
    ) {
        if (trim($externalEventId) === '') {
            throw new InvalidArgumentException('A bill payment webhook notice needs an event id.');
        }

        if (self::isBlank($providerRequestId) && self::isBlank($providerTransactionId)) {
            throw new InvalidArgumentException('A bill payment webhook notice needs a payment reference.');
        }
    }

    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
