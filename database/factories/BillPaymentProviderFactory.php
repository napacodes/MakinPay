<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillPaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active, non-system provider with no fees. The seeded `manual` provider
 * is reference data; tests that need it read it rather than create one.
 *
 * @extends Factory<BillPaymentProvider>
 */
class BillPaymentProviderFactory extends Factory
{
    protected $model = BillPaymentProvider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->slug(2);

        return [
            'plugin_id'       => null,
            'code'            => $code,
            'name'            => ucwords(str_replace('-', ' ', $code)),
            'driver'          => 'manual',
            'logo'            => null,
            'description'     => fake()->sentence(),
            'status'          => true,
            'is_system'       => false,
            'fee_percent'     => '0',
            'fee_fixed'       => '0',
            'fee_currency'    => null,
            'processing_note' => null,
            'config'          => null,
            'sort_order'      => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}
