<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use InvalidArgumentException;

/**
 * One customer request to pay a bill, as the web form or the API sent it.
 *
 * Nothing in it is trusted. The service re-reads the wallet, biller and item
 * and prices the payment itself. `customerNumber` is raw (BillerRules
 * normalises it), `amount` and `expectedTotal` are decimal strings, and
 * `idempotencyKey` is already namespaced: `web:<uuid>` or `api:<sha256>`.
 */
readonly class BillPaymentSubmission
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_API = 'api';

    public function __construct(
        public int $walletId,
        public int $billerId,
        public ?int $billerItemId,
        public ?string $amount,
        public string $customerNumber,
        public ?string $idempotencyKey,
        public ?string $expectedTotal,
        public string $channel,
    ) {
        if (! in_array($channel, [self::CHANNEL_WEB, self::CHANNEL_API], true)) {
            throw new InvalidArgumentException("Unknown bill payment channel [{$channel}].");
        }
    }
}
