<?php

namespace Database\Factories;

use App\Enums\EnvironmentMode;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\MerchantWebhookDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantWebhookDelivery>
 */
class MerchantWebhookDeliveryFactory extends Factory
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
                    'user_id'         => $user->id,
                    'business_name'   => fake()->company(),
                    'site_url'        => fake()->url(),
                    'currency_id'     => $currency->id,
                    'api_secret'      => 'live_secret_'.Str::random(8),
                    'test_api_secret' => 'test_secret_'.Str::random(8),
                ])->id;
            },
            'transaction_id'   => null,
            'trx_id'           => 'TXN'.Str::upper(Str::random(12)),
            'event'            => 'payment.completed',
            'environment'      => EnvironmentMode::PRODUCTION->value,
            'url'              => 'https://1.1.1.1/webhook',
            'payload'          => '{"data":{},"status":"completed"}',
            'status'           => MerchantWebhookDelivery::STATUS_PENDING,
            'attempts'         => 0,
            'last_status_code' => null,
            'last_error'       => null,
            'next_attempt_at'  => now(),
            'delivered_at'     => null,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status'           => MerchantWebhookDelivery::STATUS_DELIVERED,
            'attempts'         => 1,
            'last_status_code' => 200,
            'next_attempt_at'  => null,
            'delivered_at'     => now(),
        ]);
    }
}
