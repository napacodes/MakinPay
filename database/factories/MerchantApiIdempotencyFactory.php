<?php

namespace Database\Factories;

use App\Enums\EnvironmentMode;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\MerchantApiIdempotency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantApiIdempotency>
 */
class MerchantApiIdempotencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => function (): int {
                $currency = Currency::factory()->create();
                $user     = User::factory()->create();

                return Merchant::query()->create([
                    'user_id'       => $user->id,
                    'business_name' => fake()->company(),
                    'site_url'      => fake()->url(),
                    'currency_id'   => $currency->id,
                ])->id;
            },
            'environment'           => EnvironmentMode::SANDBOX,
            'idempotency_key'       => (string) Str::uuid(),
            'request_hash'          => hash('sha256', fake()->uuid()),
            'response_status'       => 201,
            'response_content_type' => 'application/json',
            'response_body'         => '{"data":{}}',
            'expires_at'            => now()->addDay(),
        ];
    }
}
