<?php

namespace App\Contracts\Kyc;

use App\Models\KycSubmission;
use App\Services\Kyc\KycVerificationResult;
use Illuminate\Http\Request;

interface KycVerificationProviderInterface
{
    /**
     * Submit the user's documents/data for verification. May return a terminal
     * verdict (sync) or a Pending result (async — the provider will later call
     * our webhook).
     */
    public function verify(KycSubmission $submission): KycVerificationResult;

    /**
     * Translate an inbound provider webhook into a normalized result. Must
     * verify the provider signature and return a Review result (never throw)
     * when the payload cannot be trusted.
     */
    public function handleWebhook(Request $request): KycVerificationResult;

    /**
     * Poll the provider for a previously submitted verification (fallback for a
     * missed webhook).
     */
    public function checkStatus(KycSubmission $submission): KycVerificationResult;

    /**
     * Underlying driver code (smile_id|shufti|sumsub|manual).
     */
    public function driver(): string;
}
