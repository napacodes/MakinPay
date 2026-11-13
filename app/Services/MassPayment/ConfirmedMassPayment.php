<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Models\MassPaymentBatch;

/**
 * The outcome of a confirm: the batch, and whether this call reserved it or
 * found it already confirmed by an earlier one (`replayed`). A replay debited
 * nothing.
 */
readonly class ConfirmedMassPayment
{
    public function __construct(
        public MassPaymentBatch $batch,
        public bool $replayed,
    ) {}
}
