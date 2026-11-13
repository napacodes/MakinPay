<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The recipient wallet of a mass payment line changed between the processor's
 * decision to pay it and the lock taken to do so: it was deactivated, moved
 * to another owner or currency, or its currency was switched off.
 *
 * Thrown inside the pay transaction before any money moves, so the
 * transaction rolls back with nothing written. The processor catches it and
 * decides the line once more from scratch, which now refunds it. It never
 * reaches a customer.
 */
class MassPaymentRecipientStateChangedException extends RuntimeException
{
    public function __construct(public readonly int $rowId)
    {
        parent::__construct("The recipient wallet of mass payment row [{$rowId}] changed before it could be paid.");
    }
}
