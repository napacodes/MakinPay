<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Biller;
use App\Models\BillerItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active package on a fixed-amount biller, priced in the biller's currency.
 *
 * @extends Factory<BillerItem>
 */
class BillerItemFactory extends Factory
{
    protected $model = BillerItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'biller_id'     => Biller::factory()->fixed(),
            'name'          => ucwords(fake()->words(2, true)),
            'external_code' => null,
            'amount'        => '10.00000000',
            'status'        => true,
            'sort_order'    => 0,
        ];
    }
}
