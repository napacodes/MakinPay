<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\KycTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KycTemplate>
 */
class KycTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title'       => ucfirst(fake()->unique()->words(2, true)).' Verification',
            'description' => fake()->sentence(),
            'fields'      => [
                ['label' => 'Full Name', 'type' => 'text', 'required' => 'true'],
                ['label' => 'ID Document', 'type' => 'file', 'required' => 'true'],
            ],
            'applicable_to' => [UserRole::USER->value],
            'status'        => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    public function withFields(array $fields): static
    {
        return $this->state(fn (): array => ['fields' => $fields]);
    }

    /**
     * @param array<int, string> $roles
     */
    public function forRoles(array $roles): static
    {
        return $this->state(fn (): array => ['applicable_to' => $roles]);
    }
}
