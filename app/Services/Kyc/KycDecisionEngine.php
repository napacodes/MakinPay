<?php

namespace App\Services\Kyc;

use App\Enums\Kyc\KycVerdict;
use App\Enums\KycReviewAction;
use App\Enums\KycStatus;
use App\Enums\KycVerificationMethod;
use App\Models\KycReviewLog;
use App\Models\KycSubmission;
use Illuminate\Support\Facades\DB;

/**
 * Turns a normalized provider result into a KYC status transition and an
 * immutable audit entry. This is the single decision path shared by the
 * synchronous verify job and the asynchronous webhook.
 */
class KycDecisionEngine
{
    public function apply(KycSubmission $submission, KycVerificationResult $result): KycStatus
    {
        $approveBelow = KycAutomationSettings::approveBelow();
        $rejectAbove  = KycAutomationSettings::rejectAbove();

        [$status, $action] = match (true) {
            $result->verdict === KycVerdict::Pending                                       => [KycStatus::PENDING, KycReviewAction::ProviderPending],
            $result->verdict === KycVerdict::Clear && $result->riskScore < $approveBelow   => [KycStatus::APPROVED, KycReviewAction::AutoApproved],
            $result->verdict === KycVerdict::Rejected || $result->riskScore > $rejectAbove => [KycStatus::REJECTED, KycReviewAction::AutoRejected],
            default                                                                        => [KycStatus::PENDING, KycReviewAction::RoutedToManual],
        };

        return DB::transaction(function () use ($submission, $result, $status, $action): KycStatus {
            $previousStatus = $submission->status;

            $submission->update([
                'status'              => $status,
                'verification_method' => KycVerificationMethod::Auto,
                'provider'            => $result->payload['provider'] ?? $submission->provider,
                'provider_reference'  => $result->providerReference   ?? $submission->provider_reference,
                'risk_score'          => $result->riskScore,
                'auto_result'         => [
                    'verdict' => $result->verdict->value,
                    'checks'  => $result->checks,
                    'reason'  => $result->reason,
                ],
                'verified_at' => $status === KycStatus::APPROVED ? now() : $submission->verified_at,
            ]);

            KycReviewLog::create([
                'kyc_submission_id' => $submission->id,
                'admin_id'          => null,
                'action'            => $action,
                'from_status'       => $previousStatus,
                'to_status'         => $status,
                'remarks'           => $result->reason,
            ]);

            return $status;
        });
    }
}
