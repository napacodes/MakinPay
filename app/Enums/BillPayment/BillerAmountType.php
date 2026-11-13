<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * How a biller decides what the customer pays.
 *
 *   Open   the customer types the amount, inside the biller's min and max.
 *   Fixed  the customer picks one of the biller's items (a TV bouquet, a
 *          school fee) and pays its price.
 */
enum BillerAmountType: string
{
    case Open  = 'open';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Open  => __('Customer enters the amount'),
            self::Fixed => __('Fixed packages'),
        };
    }
}
