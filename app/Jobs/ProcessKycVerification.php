<?php

namespace App\Jobs;

use App\Enums\Kyc\KycVerdict;
use App\Enums\KycReviewAction;
use App\Enums\KycStatus;
use App\Enums\KycVerificationMethod;
use App\Models\KycReviewLog;
use App\Models\KycSubmission;
use App\Services\Kyc\KycAutomationSettings;
use App\Services\Kyc\KycDecisionEngine;
use App\Services\Kyc\KycProviderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the automated KYC pipeline for a freshly submitted (or resubmitted)
 * KYC. Resolves the country-appropriate provider, calls it, and lets the
 * decision engine action the result. Provider failures leave the submission
 * PENDING so a human still reviews it.
 */
class ProcessKycVerification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $kycSubmissionId) {}

    /**
     * Prevent duplicate provider calls if the job is dispatched twice.
     */
    public function uniqueId(): string
    {
        return (string) $this->kycSubmissionId;
    }

    public function handle(KycProviderRegistry $registry, KycDecisionEngine $engine): void
    {
        $submission = KycSubmission::query()
            ->with(['kycTemplate', 'user'])
            ->find($this->kycSubmissionId);

        if (! $submission || $submission->status !== KycStatus::PENDING) {
            return;
        }

        // Last line of defence. Automated verification ships the applicant's
        // name, document images and ID number to a third party, so a recorded
        // agreement is a precondition for the call — not merely a form field.
        // Without one the submission simply waits for a human.
        if (KycAutomationSettings::requiresConsent() && ! $submission->hasVerificationConsent()) {
            KycReviewLog::create([
                'kyc_submission_id' => $submission->id,
                'admin_id'          => null,
                'action'            => KycReviewAction::RoutedToManual,
                'from_status'       => $submission->status,
                'to_status'         => KycStatus::PENDING,
                'remarks'           => __('Automated verification skipped: the applicant has not agreed to their documents being shared with the verification provider.'),
            ]);

            return;
        }

        $provider = $registry->forSubmission($submission);
        $result   = $provider->verify($submission);

        // Provider works asynchronously — record the reference and wait for the
        // webhook rather than deciding now.
        if ($result->verdict === KycVerdict::Pending) {
            $submission->update([
                'verification_method' => KycVerificationMethod::Auto,
                'provider'            => $result->payload['provider'] ?? $provider->driver(),
                'provider_reference'  => $result->providerReference,
            ]);

            return;
        }

        $engine->apply($submission, $result);
    }

    public function failed(Throwable $e): void
    {
        Log::error("Auto-KYC verification failed for submission {$this->kycSubmissionId}: ".$e->getMessage());
    }
}
