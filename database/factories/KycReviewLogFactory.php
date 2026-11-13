<?php

namespace Database\Factories;

use App\Enums\KycReviewAction;
use App\Enums\KycStatus;
use App\Models\KycReviewLog;
use App\Models\KycSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KycReviewLog>
 */
class KycReviewLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kyc_submission_id' => KycSubmission::factory(),
            'admin_id'          => null,
            'action'            => KycReviewAction::Submitted,
            'from_status'       => null,
            'to_status'         => KycStatus::PENDING,
            'remarks'           => null,
        ];
    }
}
