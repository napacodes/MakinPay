<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

use App\Exceptions\InvalidBillPaymentWebhookException;
use App\Services\BillPayment\BillPaymentWebhookNotice;
use Illuminate\Http\Request;

/**
 * A bill payment driver whose provider calls back when a payment changes.
 *
 * Optional, checked with instanceof. A webhook never moves money: the notice
 * only names which payment to ask about, and the payment is then checked
 * with the provider through {@see ChecksBillPaymentStatus::status()}. Only
 * that answer is ever applied.
 */
interface ReceivesBillPaymentWebhooks
{
    /**
     * Authenticate one callback and say which payment it is about.
     *
     * Returns null for a genuine callback that is not about a bill payment
     * (another event type, or one naming no reference).
     *
     * @throws InvalidBillPaymentWebhookException when the callback cannot be authenticated (no secret configured, a missing or wrong signature)
     */
    public function webhookNotice(Request $request): ?BillPaymentWebhookNotice;
}
