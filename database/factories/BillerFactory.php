<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillPayment\BillerAmountType;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillPaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active, open-amount USD biller with no bounds and no fee override, in a
 * fresh category under a fresh provider.
 *
 * @extends Factory<Biller>
 */
class BillerFactory extends Factory
{
    protected $model = Biller::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_category_id'         => BillCategory::factory(),
            'bill_payment_provider_id' => BillPaymentProvider::factory(),
            'name'                     => fake()->company().' Utilities',
            'country'                  => 'US',
            'currency'                 => 'USD',
            'logo'                     => null,
            'description'              => null,
            'customer_number_label'    => 'Account number',
            'customer_number_example'  => null,
            'customer_number_help'     => null,
            'customer_number_pattern'  => null,
            'amount_type'              => BillerAmountType::Open->value,
            'min_amount'               => null,
            'max_amount'               => null,
            'fee_percent'              => null,
            'fee_fixed'                => null,
            'token_label'              => null,
            'processing_note'          => null,
            'instructions'             => null,
            'external_code'            => null,
            'external_meta'            => null,
            'status'                   => true,
            'sort_order'               => 0,
        ];
    }

    /** A package biller; give it items with BillerItem::factory(). */
    public function fixed(): static
    {
        return $this->state(fn (): array => ['amount_type' => BillerAmountType::Fixed->value]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}
