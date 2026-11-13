<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Constants\CurrencyRole as CurrencyRoleName;
use App\Models\Currency;
use App\Models\CurrencyRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyRole>
 */
class CurrencyRoleFactory extends Factory
{
    protected $model = CurrencyRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency_id' => Currency::factory(),
            'role_name'   => CurrencyRoleName::EXCHANGE,
            'min_limit'   => 0,
            'max_limit'   => 1000000,
            'fee_type'    => 'fixed',
            'fee'         => 0,
            'is_active'   => true,
        ];
    }

    public function role(string $roleName): static
    {
        return $this->state(fn (): array => ['role_name' => $roleName]);
    }

    public function fixedFee(string|int|float $fee): static
    {
        return $this->state(fn (): array => ['fee_type' => 'fixed', 'fee' => $fee]);
    }

    public function percentFee(string|int|float $fee): static
    {
        return $this->state(fn (): array => ['fee_type' => 'percent', 'fee' => $fee]);
    }

    public function limits(string|int|float $min, string|int|float|null $max = null): static
    {
        return $this->state(fn (): array => ['min_limit' => $min, 'max_limit' => $max]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
