<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Transaction as TransactionModel;
use Exception;

/**
 * A merchant payment that was refused after the checkout had already been
 * failed and the merchant told about it.
 *
 * This exists because the payer-side flow has two different kinds of "no".
 * A NotifyErrorException means the attempt never started - a wrong PIN, a
 * missing wallet - and the checkout is still open for another try. This one
 * means the checkout itself has been closed: Transaction::failTransaction has
 * run, the IPN has gone out, and the merchant's own cancel page is where the
 * customer belongs.
 *
 * The distinction was implicit before, in the difference between a `throw` and
 * a `return $this->handleFailure(...)` inside one controller method. Lifting
 * the flow into a service made it something that has to be said out loud, and
 * saying it out loud is what lets the mobile API answer the two cases
 * differently - which a caller that has no redirect to follow has to.
 */
class MerchantPaymentFailed extends Exception
{
    public function __construct(
        public readonly TransactionModel $transaction,
        public readonly string $redirectUrl,
        string $message,
    ) {
        parent::__construct($message);
    }
}
