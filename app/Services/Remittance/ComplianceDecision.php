<?php

namespace App\Services\Remittance;

class ComplianceDecision
{
    public function __construct(
        public bool $passed,
        public string $decision,
        public int $riskScore,
        public string $provider,
        public ?string $providerReference = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return array{passed: bool, decision: string, risk_score: int, provider: string, provider_reference: ?string, reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'passed'             => $this->passed,
            'decision'           => $this->decision,
            'risk_score'         => $this->riskScore,
            'provider'           => $this->provider,
            'provider_reference' => $this->providerReference,
            'reason'             => $this->reason,
        ];
    }
}
