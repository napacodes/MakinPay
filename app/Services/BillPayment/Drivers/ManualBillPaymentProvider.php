<?php

declare(strict_types=1);

namespace App\Services\BillPayment\Drivers;

use App\Contracts\BillPayment\BillPaymentProviderInterface;
use App\Contracts\BillPayment\FulfilledByOperator;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillPaymentOrder;
use App\Services\BillPayment\BillPaymentResult;

/**
 * The operator-paid driver: someone on the admin side pays the biller, at the
 * counter or in a banking app, and records the receipt from the queue.
 *
 * pay() does no I/O. It answers Pending, which leaves the payment in
 * `awaiting_operator`. FulfilledByOperator is what makes the service record
 * `fulfilment = operator` when the money is reserved. There is nothing to ask
 * for a status or to probe, so it implements neither capability.
 */
class ManualBillPaymentProvider implements BillPaymentProviderInterface, FulfilledByOperator
{
    private ?BillPaymentProvider $provider = null;

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $config
     */
    public function configure(BillPaymentProvider $provider, array $credentials, array $config): void
    {
        $this->provider = $provider;
    }

    /** The provider row this driver was configured for; null before configure(). */
    public function provider(): ?BillPaymentProvider
    {
        return $this->provider;
    }

    public function pay(BillPaymentOrder $order): BillPaymentResult
    {
        return BillPaymentResult::pending(__('Queued for an operator.'));
    }
}
