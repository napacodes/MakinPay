<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Models\BillPayment;

/**
 * The payment a submission produced. `replayed` is true when the request's
 * idempotency key matched an earlier payment, in which case nothing was
 * debited this time and the earlier payment is returned as it now stands.
 */
readonly class SubmittedBillPayment
{
    public function __construct(
        public BillPayment $payment,
        public bool $replayed,
    ) {}
}
