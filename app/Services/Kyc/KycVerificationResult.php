<?php

namespace App\Services\Kyc;

use App\Enums\Kyc\KycVerdict;

/**
 * Normalized verification outcome returned by every KYC provider driver, so
 * the decision engine and webhook handler never need provider-specific logic.
 */
class KycVerificationResult
{
    /**
     * @param array<string, mixed> $checks  Sub-check breakdown: doc_authenticity, face_match, liveness, aml.
     * @param array<string, mixed> $payload Raw provider response, kept for audit (auto_result).
     */
    public function __construct(
        public KycVerdict $verdict,
        public int $riskScore = 0,
        public ?string $providerReference = null,
        public ?string $reason = null,
        public array $checks = [],
        public array $payload = [],
    ) {}

    public function isTerminal(): bool
    {
        return $this->verdict->isTerminal();
    }

    public static function pending(?string $providerReference = null, array $payload = []): self
    {
        return new self(KycVerdict::Pending, 0, $providerReference, null, [], $payload);
    }

    public static function review(?string $reason = null, int $riskScore = 50, ?string $providerReference = null): self
    {
        return new self(KycVerdict::Review, $riskScore, $providerReference, $reason);
    }
}
