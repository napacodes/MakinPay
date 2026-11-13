<?php

namespace App\Services\Payment\Payout;

use App\Enums\RemittanceStatus;

class PayoutResult
{
    /**
     * `amount` and `currency` are what the provider itself reported for the
     * payout, when it reported them: the amount as an exact decimal string in
     * major units, the currency as an upper-case code.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public RemittanceStatus $status,
        public ?string $gatewayReference = null,
        public ?string $message = null,
        public array $payload = [],
        public ?string $amount = null,
        public ?string $currency = null,
    ) {}

    public function isSuccessful(): bool
    {
        return in_array($this->status, [
            RemittanceStatus::Submitted,
            RemittanceStatus::Processing,
            RemittanceStatus::Completed,
        ], true);
    }

    public function isFailed(): bool
    {
        return $this->status === RemittanceStatus::Failed;
    }
}
