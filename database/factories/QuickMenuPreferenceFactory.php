<?php

namespace Database\Factories;

use App\Models\QuickMenuPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickMenuPreference>
 */
class QuickMenuPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'items'   => [
                'user.wallet.index',
                'user.send-money.create',
                'user.deposit.create',
                'user.transaction.index',
            ],
        ];
    }
}
