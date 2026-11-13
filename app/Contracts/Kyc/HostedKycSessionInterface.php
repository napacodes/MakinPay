<?php

declare(strict_types=1);

namespace App\Contracts\Kyc;

use App\Models\KycSubmission;
use App\Services\Kyc\KycHostedSession;

/**
 * Implemented by drivers that can host the document capture themselves.
 *
 * A provider implementing this replaces our template form entirely: the
 * applicant is sent to the provider, photographs their documents there, and the
 * verdict comes back on the existing webhook. Drivers that only accept
 * documents we have already collected do not implement it, and their applicants
 * keep the template form.
 */
interface HostedKycSessionInterface extends KycVerificationProviderInterface
{
    /**
     * Open a hosted journey for this submission.
     *
     * Returns null when the session could not be opened — missing credentials,
     * a provider error, an unreachable API. The caller is expected to treat
     * that as "not available right now" rather than as a rejection.
     *
     * @param string $returnUrl Where the provider should send the applicant back to.
     */
    public function createHostedSession(KycSubmission $submission, string $returnUrl): ?KycHostedSession;

    /**
     * The administrator-given name for this connection, shown to the applicant
     * before they are handed over.
     */
    public function providerName(): string;
}
