<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillPaymentOrder;
use App\Services\BillPayment\BillPaymentResult;

/**
 * A fulfiller of bill payments: the Manual driver, where an operator pays the
 * biller, or (from GF3) an API provider such as Reloadly or Flutterwave.
 *
 * BillPaymentProviderManager builds one per payment, from the provider that
 * payment was pinned to when its money was reserved, and calls configure()
 * once before anything else. There is no site-wide "active provider".
 *
 * Optional capabilities are separate interfaces, checked with instanceof:
 * {@see FulfilledByOperator}, {@see ChecksBillPaymentStatus} and
 * {@see ProvidesBillPaymentDiagnostics}.
 */
interface BillPaymentProviderInterface
{
    /**
     * Called once by the manager per resolution. Replaces the recharge
     * manager's instanceof chain: every driver is hydrated the same way.
     *
     * @param array<string, mixed> $credentials the provider plugin's credentials with secrets revealed; empty without a plugin
     * @param array<string, mixed> $config      the provider row's `config` column; empty when unset
     */
    public function configure(BillPaymentProvider $provider, array $credentials, array $config): void;

    /**
     * Hand one reserved (already debited, committed) payment to the fulfiller.
     *
     * $order->providerRequestId is stable for the payment's life and MUST be
     * sent as the provider-side idempotency key, so a retry can never pay the
     * biller twice.
     *
     * Return Failed ONLY when certain nothing was charged: Failed refunds the
     * customer. A timeout, 5xx or unparseable reply returns Unknown or throws
     * (any Throwable, incl. App\Exceptions\ProviderOutcomeUnknownException);
     * the service treats a throw as Unknown and never refunds it.
     *
     * Called with no database transaction open and no lock held.
     */
    public function pay(BillPaymentOrder $order): BillPaymentResult;
}
