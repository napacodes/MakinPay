<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * What a fulfiller said about one payment.
 *
 * Unknown is not Failed: a timeout or an unreadable reply means the biller
 * may have been paid, so an Unknown outcome is never refunded automatically.
 */
enum BillPaymentOutcome: string
{
    case Completed = 'completed';
    case Failed    = 'failed';
    case Pending   = 'pending';
    case Unknown   = 'unknown';
}
