<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * Who moved a bill payment from one state to the next, as recorded on each
 * bill_payment_events row.
 */
enum BillPaymentActorType: string
{
    case System   = 'system';
    case User     = 'user';
    case Admin    = 'admin';
    case Provider = 'provider';
}
