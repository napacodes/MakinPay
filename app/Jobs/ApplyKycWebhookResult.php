<?php

namespace App\Jobs;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Services\Kyc\KycDecisionEngine;
use App\Services\Kyc\KycVerificationResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies an already-normalized provider webhook result to the matching
 * submission. Kept idempotent: only a still-PENDING submission is actioned, so
 * a replayed webhook is a no-op.
 */
class ApplyKycWebhookResult implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $providerReference,
        public KycVerificationResult $result,
    ) {}

    public function handle(KycDecisionEngine $engine): void
    {
        /*
         * Either identifier. A provider knows a submission by its own id and
         * by the one we gave it, and which of the two reached
         * provider_reference has not been consistent: hosted Sumsub sessions
         * stored our externalUserId while the webhook arrived carrying
         * Sumsub's applicantId, so the lookup missed, a real GREEN verdict
         * was dropped and the applicant stayed PENDING for good.
         *
         * Storing the applicantId fixes that from here on. This is for the
         * submissions already sitting on installations with the other kind of
         * reference — their verdict still has to find them.
         */
        $submission = $this->locate($this->providerReference)
            ?? $this->locate((string) ($this->result->payload['externalUserId'] ?? ''));

        if (! $submission) {
            return;
        }

        $engine->apply($submission, $this->result);
    }

    private function locate(string $reference): ?KycSubmission
    {
        if ($reference === '') {
            return null;
        }

        return KycSubmission::query()
            ->with(['kycTemplate', 'user'])
            ->where('provider_reference', $reference)
            ->where('status', KycStatus::PENDING)
            ->first();
    }
}
