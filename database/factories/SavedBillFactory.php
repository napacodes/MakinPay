<?php

namespace Database\Factories;

use App\Models\Biller;
use App\Models\SavedBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedBill>
 */
class SavedBillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'biller_id'       => Biller::factory(),
            'customer_number' => (string) fake()->numerify('############'),
            'nickname'        => fake()->randomElement(['Home meter', 'Living room TV', 'Office internet']),
            'last_used_at'    => now(),
        ];
    }
}
