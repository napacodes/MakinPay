<?php

namespace App\Services\Payment\Payout;

use App\Models\Beneficiary;
use App\Models\RemittanceTransfer;

interface PayoutGateway
{
    /**
     * Run a read-only provider connectivity probe.
     *
     * @return array{ok: bool, message: string}
     */
    public function healthCheck(): array;

    /**
     * Validate provider-specific recipient data before any wallet debit occurs.
     */
    public function validateBeneficiary(Beneficiary $beneficiary): void;

    /**
     * Send funds to the beneficiary via the underlying provider. Every driver
     * must pass `provider_idempotency_key` to the upstream provider so retrying
     * a timed-out request cannot create a second payout.
     *
     * @return PayoutResult Carrier object with status + gateway reference + raw payload.
     */
    public function payout(RemittanceTransfer $transfer): PayoutResult;

    /**
     * Poll the provider for the current status of a previously submitted payout.
     */
    public function checkStatus(RemittanceTransfer $transfer): PayoutResult;

    /**
     * Driver code (matches `remittance_corridors.payout_gateway`).
     */
    public function code(): string;
}
