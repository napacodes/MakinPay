<?php

declare(strict_types=1);

namespace App\Enums\BillPayment;

/**
 * What a provider said when asked who owns a customer number.
 *
 *   Verified     the provider knows the account (it may also give a name).
 *   NotFound     the provider definitely has no such account.
 *   Unavailable  no clear answer: a timeout, an error, or a reply about a
 *                different biller. Never treated as NotFound.
 */
enum BillCustomerLookupStatus: string
{
    case Verified    = 'verified';
    case NotFound    = 'not_found';
    case Unavailable = 'unavailable';
}
