<?php

namespace App\Contracts\Remittance;

use App\Models\Beneficiary;
use App\Models\User;
use App\Services\Remittance\ComplianceDecision;

interface ComplianceProvider
{
    public function code(): string;

    /**
     * Run a read-only connectivity check without submitting customer data.
     *
     * @return array{ok: bool, message: string}
     */
    public function healthCheck(): array;

    public function screen(
        User $sender,
        Beneficiary $beneficiary,
        string $amount,
        string $currency,
    ): ComplianceDecision;
}
