<?php

namespace App\Services\Kyc\Providers;

use App\Contracts\Kyc\KycVerificationProviderInterface;
use App\Models\KycSubmission;
use App\Services\Kyc\KycVerificationResult;
use Illuminate\Http\Request;

/**
 * Null-object driver. Every verdict routes to human review, so a workspace with
 * no configured provider (or a country with no coverage) behaves exactly like
 * the pre-auto manual KYC flow.
 */
class ManualKycProvider implements KycVerificationProviderInterface
{
    public function verify(KycSubmission $submission): KycVerificationResult
    {
        return KycVerificationResult::review(__('No automated provider configured; routed to manual review.'), 50);
    }

    public function handleWebhook(Request $request): KycVerificationResult
    {
        return KycVerificationResult::review(__('Manual provider does not accept webhooks.'), 50);
    }

    public function checkStatus(KycSubmission $submission): KycVerificationResult
    {
        return KycVerificationResult::review(null, 50);
    }

    public function driver(): string
    {
        return 'manual';
    }
}
