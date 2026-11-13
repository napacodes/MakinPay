<?php

namespace Database\Factories;

use App\Models\LoginOtpCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<LoginOtpCode>
 */
class LoginOtpCodeFactory extends Factory
{
    protected $model = LoginOtpCode::class;

    /**
     * A live code "123456" for a fresh user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'phone_number' => '+1555'.fake()->numerify('#######'),
            'code_hash'    => Hash::make('123456'),
            'attempts'     => 0,
            'expires_at'   => now()->addMinutes(5),
            'consumed_at'  => null,
            'requested_ip' => '127.0.0.1',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => ['consumed_at' => now()]);
    }
}
