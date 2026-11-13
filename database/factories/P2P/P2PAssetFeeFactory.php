<?php

declare(strict_types=1);

namespace Database\Factories\P2P;

use App\Models\Currency;
use App\Models\P2P\P2PAssetFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<P2PAssetFee>
 */
class P2PAssetFeeFactory extends Factory
{
    protected $model = P2PAssetFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency_id'     => Currency::factory(),
            'maker_fee_pct'   => 0,
            'maker_fee_fixed' => 0,
            'maker_fee_min'   => null,
            'maker_fee_max'   => null,
            'taker_fee_pct'   => 0,
            'taker_fee_fixed' => 0,
            'taker_fee_min'   => null,
            'taker_fee_max'   => null,
            'is_active'       => true,
        ];
    }

    public function forCurrency(Currency $currency): static
    {
        return $this->state(fn (): array => ['currency_id' => $currency->id]);
    }

    /**
     * Accepts strings so a satoshi-scale fee survives the factory.
     */
    public function maker(string|int|float $fixed, string|int|float $percent = 0): static
    {
        return $this->state(fn (): array => [
            'maker_fee_fixed' => $fixed,
            'maker_fee_pct'   => $percent,
        ]);
    }

    public function taker(string|int|float $fixed, string|int|float $percent = 0): static
    {
        return $this->state(fn (): array => [
            'taker_fee_fixed' => $fixed,
            'taker_fee_pct'   => $percent,
        ]);
    }

    /** @param array<string, mixed> $bounds */
    public function bounds(array $bounds): static
    {
        return $this->state(fn (): array => $bounds);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
