<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Tax\TaxableType;
use App\Enums\Tax\TaxBase;
use App\Enums\Tax\TaxScope;
use App\Models\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRule>
 */
class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    /**
     * An active 5% tax on the amount sent, for everyone.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'             => 'VAT '.$this->faker->unique()->numberBetween(1, 99999),
            'transaction_type' => TaxableType::SendMoney->value,
            'scope'            => TaxScope::All->value,
            'countries'        => null,
            'percent'          => '5',
            'fixed_amount'     => '0',
            'base'             => TaxBase::Amount->value,
            'is_active'        => true,
            'sort_order'       => 0,
        ];
    }

    public function forType(TaxableType $type): static
    {
        return $this->state(['transaction_type' => $type->value]);
    }

    /**
     * @param list<string> $countries
     */
    public function scoped(TaxScope $scope, array $countries = []): static
    {
        return $this->state(['scope' => $scope->value, 'countries' => $countries === [] ? null : $countries]);
    }

    public function onFee(): static
    {
        return $this->state(['base' => TaxBase::Fee->value]);
    }

    /**
     * Charged by amount brackets instead of one rate.
     *
     * @param list<array{up_to: ?string, percent?: string, fixed?: string}> $brackets
     */
    public function bracketed(array $brackets): static
    {
        return $this->state([
            'percent'      => '0',
            'fixed_amount' => '0',
            'brackets'     => array_map(static fn (array $bracket): array => [
                'up_to'   => $bracket['up_to'],
                'percent' => $bracket['percent'] ?? '0',
                'fixed'   => $bracket['fixed']   ?? '0',
            ], $brackets),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
