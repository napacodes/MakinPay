<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * Who pays the biller, decided when the money is reserved and never changed.
 *
 *   Operator  a person on the admin side pays and records the receipt.
 *   Provider  an API provider pays and reports the result.
 */
enum BillPaymentFulfilment: string
{
    case Operator = 'operator';
    case Provider = 'provider';
}
