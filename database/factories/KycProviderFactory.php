<?php

namespace Database\Factories;

use App\Models\KycProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KycProvider>
 */
class KycProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code'        => 'kyc-'.fake()->unique()->slug(2),
            'name'        => ucfirst(fake()->unique()->words(2, true)),
            'driver'      => 'shufti',
            'countries'   => ['*'],
            'priority'    => 0,
            'mode'        => 'sandbox',
            'credentials' => ['client_id' => 'test_id', 'secret_key' => 'test_secret'],
            'status'      => true,
        ];
    }

    /**
     * @param array<int, string> $countries
     */
    public function driver(string $driver, array $countries = ['*'], int $priority = 0): static
    {
        return $this->state(fn (): array => [
            'driver'    => $driver,
            'countries' => $countries,
            'priority'  => $priority,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }

    public function withoutCredentials(): static
    {
        return $this->state(fn (): array => ['credentials' => null]);
    }
}
