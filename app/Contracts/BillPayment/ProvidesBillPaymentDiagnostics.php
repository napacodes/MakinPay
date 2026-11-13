<?php

declare(strict_types=1);

namespace App\Contracts\BillPayment;

/**
 * A bill payment driver that can prove whether its credentials work.
 *
 * The provider screen shows "Test connection" only for a driver that
 * implements this. The shape matches the mobile recharge and virtual card
 * diagnostics, so one admin UI can render any of them.
 */
interface ProvidesBillPaymentDiagnostics
{
    /**
     * Probe the provider's credentials and reachability.
     *
     * Must never throw: a failed probe is a result, not an exception. `details`
     * never carries a secret.
     *
     * @return array{ok: bool, mode: string, message: string, latency_ms: int|null, details: array<string, mixed>}
     */
    public function testConnection(): array;
}
