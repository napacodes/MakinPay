<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\EnvironmentMode;
use App\Enums\MerchantSubscriptionStatus;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantSubscription>
 */
class MerchantSubscriptionFactory extends Factory
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
            'environment'          => EnvironmentMode::SANDBOX,
            'external_reference'   => 'SUB-'.Str::upper(Str::random(12)),
            'status'               => MerchantSubscriptionStatus::Pending,
            'billing_cycle'        => BillingCycle::Monthly,
            'amount'               => fake()->randomFloat(2, 5, 500),
            'currency_code'        => 'USD',
            'customer_name'        => fake()->name(),
            'customer_email'       => fake()->safeEmail(),
            'description'          => 'Monthly service subscription',
            'success_redirect'     => 'https://merchant.example/billing/success',
            'cancel_redirect'      => 'https://merchant.example/billing/cancel',
            'ipn_url'              => 'https://merchant.example/webhooks/digikash',
            'starts_at'            => now(),
            'next_billing_at'      => now(),
            'cancel_at_period_end' => false,
            'metadata'             => [],
        ];
    }
}
