<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

use App\Models\Biller;
use App\Models\BillerItem;
use App\Services\BillPayment\BillCustomerLookup;
use App\Services\BillPayment\BillCustomerQuery;

/**
 * A bill payment driver that can ask its provider who owns a customer number
 * (a meter, a smartcard, an account) before any money moves.
 *
 * Optional, checked with instanceof like the other capabilities. A lookup is
 * advice about the account, never about money: a Verified answer does not
 * make a payment safe to settle, and a NotFound answer only stops a payment
 * before it is debited.
 */
interface ValidatesBillCustomer
{
    /**
     * Whether this biller, or this item of it, can be looked up at all.
     *
     * Pure: decided from the rows alone (their codes and synced external
     * meta), with no I/O, so a form can ask it on every render.
     */
    public function supportsCustomerLookup(Biller $biller, ?BillerItem $item): bool;

    /**
     * Ask the provider about one customer number.
     *
     * Never throws. A timeout, a 5xx, an unreadable reply or an answer bound
     * to a different biller is Unavailable, not NotFound: only a definite
     * "no such account" from the provider is NotFound.
     */
    public function lookupCustomer(BillCustomerQuery $query): BillCustomerLookup;
}
