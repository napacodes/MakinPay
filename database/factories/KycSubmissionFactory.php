<?php

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Models\KycSubmission;
use App\Models\KycTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KycSubmission>
 */
class KycSubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kyc_template_id' => KycTemplate::factory(),
            'user_id'         => User::factory(),
            'submission_data' => ['Full Name' => fake()->name()],
            'status'          => KycStatus::PENDING,
            'notes'           => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => KycStatus::APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => KycStatus::REJECTED]);
    }
}
