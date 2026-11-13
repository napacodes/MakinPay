<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

use App\Services\BillPayment\BillPaymentOrder;
use App\Services\BillPayment\BillPaymentResult;

/**
 * A driver that can ask its provider what became of a payment it was handed.
 *
 * When pay() gives no clear answer (Unknown, or a throw), a payment whose
 * driver has this capability waits in `awaiting_provider` for a later status
 * check to settle or refund it. Without it the payment goes to `needs_review`
 * for a person.
 *
 * Refunds: a provider-fulfilled payment in `needs_review` is refunded only on
 * a status() result of Failed, or by an admin who checked it with the
 * provider within the last few minutes, saw no Completed or Failed answer,
 * and confirmed with a note that it was not charged
 * (BillPaymentService::confirmNotPaidAndRefund). A plain "mark failed" is
 * still refused there.
 */
interface ChecksBillPaymentStatus
{
    /**
     * The provider's current answer for one payment, looked up by
     * $order->providerTransactionId when known, otherwise by
     * $order->providerRequestId.
     *
     * Same outcome rules as pay(): Failed only when certain nothing was
     * charged; anything unclear is Unknown or a throw.
     */
    public function status(BillPaymentOrder $order): BillPaymentResult;
}
