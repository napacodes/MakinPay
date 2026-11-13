<?php

namespace Database\Factories;

use App\Enums\MerchantSubscriptionInvoiceStatus;
use App\Models\MerchantSubscription;
use App\Models\MerchantSubscriptionInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MerchantSubscriptionInvoice>
 */
class MerchantSubscriptionInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now();

        return [
            'merchant_subscription_id' => MerchantSubscription::factory(),
            'sequence'                 => 1,
            'reference'                => 'MSI-'.Str::upper(Str::random(16)),
            'status'                   => MerchantSubscriptionInvoiceStatus::Pending,
            'amount'                   => fake()->randomFloat(2, 5, 500),
            'currency_code'            => 'USD',
            'period_start'             => $periodStart,
            'period_end'               => $periodStart->copy()->addMonth(),
            'due_at'                   => $periodStart,
            'checkout_expires_at'      => $periodStart->copy()->addMinutes(900),
        ];
    }
}
