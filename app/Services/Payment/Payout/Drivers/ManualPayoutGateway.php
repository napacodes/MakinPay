<?php

namespace App\Services\Payment\Payout\Drivers;

use App\Enums\RemittanceStatus;
use App\Models\Beneficiary;
use App\Models\RemittanceTransfer;
use App\Services\Payment\Payout\PayoutGateway;
use App\Services\Payment\Payout\PayoutOrder;
use App\Services\Payment\Payout\PayoutOrderGateway;
use App\Services\Payment\Payout\PayoutRecipient;
use App\Services\Payment\Payout\PayoutResult;
use LogicException;

/**
 * Fallback payout driver. Marks transfers as Submitted (admin-approved
 * manually from the backend dashboard). Useful when a corridor doesn't
 * have an automated upstream partner yet.
 */
class ManualPayoutGateway implements PayoutGateway, PayoutOrderGateway
{
    public function healthCheck(): array
    {
        return ['ok' => true, 'message' => __('Manual payout workflow is available.')];
    }

    public function validateBeneficiary(Beneficiary $beneficiary): void {}

    public function code(): string
    {
        return 'manual';
    }

    public function payout(RemittanceTransfer $transfer): PayoutResult
    {
        return new PayoutResult(
            status: RemittanceStatus::Submitted,
            gatewayReference: 'MAN-'.strtoupper(substr((string) ($transfer->provider_idempotency_key ?? $transfer->reference), 0, 16)),
            message: __('Awaiting manual admin approval.'),
            payload: ['driver' => 'manual'],
        );
    }

    public function checkStatus(RemittanceTransfer $transfer): PayoutResult
    {
        return new PayoutResult(
            status: $transfer->status,
            gatewayReference: $transfer->gateway_reference,
        );
    }

    /**
     * An operator pays outside the system against the method's own fields,
     * which the draft already validated, so there is nothing more to check.
     */
    public function validateRecipient(PayoutRecipient $recipient): void {}

    /**
     * Hands the line to an operator. No external call is made and no
     * reference is invented, so nothing is bound until an admin records the
     * real one.
     */
    public function send(PayoutOrder $order): PayoutResult
    {
        return new PayoutResult(
            status: RemittanceStatus::Submitted,
            message: __('Awaiting manual payment by an operator.'),
            payload: ['driver' => $this->code()],
        );
    }

    public function lookup(PayoutOrder $order): PayoutResult
    {
        throw new LogicException('Manual payouts have no provider record to look up.');
    }

    public function supportsLookup(): bool
    {
        return false;
    }

    public function maxCallSeconds(): int
    {
        return 1;
    }
}
