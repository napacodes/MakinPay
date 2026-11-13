<?php

namespace Database\Factories;

use App\Models\SupportCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportCategory>
 */
class SupportCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'   => ucfirst(fake()->unique()->words(2, true)),
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}
