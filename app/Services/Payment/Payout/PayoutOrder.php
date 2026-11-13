<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One mass payout line as a payout driver sees it, with no database row
 * attached.
 *
 * `idempotencyKey` is the line's provider request id: it is the only value a
 * driver may use to make the provider deduplicate, and the only value a
 * lookup may search by when no provider reference is known yet.
 * `reference` is for descriptions only. `amount` is the exact net, in
 * `currency`, as a decimal string; it is never cast to a float.
 */
final readonly class PayoutOrder
{
    public string $currency;

    public function __construct(
        public string $idempotencyKey,
        public string $reference,
        public string $amount,
        string $currency,
        public PayoutRecipient $recipient,
        public ?string $gatewayReference = null,
        public ?CarbonImmutable $submittedAt = null,
    ) {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('A payout order needs an idempotency key.');
        }

        if (preg_match('/\A\d+(\.\d+)?\z/', $amount) !== 1 || preg_match('/[1-9]/', $amount) !== 1) {
            throw new InvalidArgumentException('A payout order amount must be a positive decimal string.');
        }

        if (preg_match('/\A[A-Za-z]{3}\z/', trim($currency)) !== 1) {
            throw new InvalidArgumentException('A payout order currency must be a three-letter code.');
        }

        $this->currency = strtoupper(trim($currency));
    }
}
