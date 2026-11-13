<?php

declare(strict_types=1);

namespace App\Contracts\Payment;

use App\Enums\TrxStatus;
use App\Models\Transaction;

/**
 * A gateway whose payment is a prompt on the payer's phone.
 *
 * Nothing redirects back from a handset: the payer approves with their PIN
 * and the provider tells us, either by callback or when asked. This is the
 * asking half, so the "approve on your phone" page can settle a payment whose
 * callback is late or never comes.
 *
 * An implementation settles only on the provider's own answer for this
 * transaction - never on anything the browser sent - and through the same
 * locked, idempotent TransactionService path as its callback, so a poll and a
 * callback arriving together settle the payment once.
 */
interface ConfirmsPushPayment
{
    /**
     * Ask the provider about this payment, settle it if the answer is final,
     * and return the status the transaction is in afterwards.
     */
    public function confirm(Transaction $transaction): TrxStatus;
}
