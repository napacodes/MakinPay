<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

/**
 * What is recorded when a payment is marked paid: the biller's receipt, the
 * token for a prepaid biller, the provider's own id and an internal note.
 *
 * The token goes onto the payment row's encrypted column and nowhere else:
 * never the ledger row, an event, a notification or a log.
 */
readonly class BillPaymentSettlement
{
    public function __construct(
        public ?string $receiptReference = null,
        public ?string $token = null,
        public ?string $providerTransactionId = null,
        public ?string $note = null,
    ) {}
}
