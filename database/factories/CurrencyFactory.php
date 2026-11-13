<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Constants\CurrencyRole as CurrencyRoleName;
use App\Constants\CurrencyType;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('???'));

        return [
            'name'          => $code.' Currency',
            'code'          => $code,
            'symbol'        => $code,
            'type'          => CurrencyType::FIAT,
            'exchange_rate' => 1,
            'rate_live'     => false,
            'auto_wallet'   => false,
            'default'       => false,
            'status'        => true,
        ];
    }

    /**
     * Give the currency a specific ISO/ticker code, deriving name and symbol from it.
     */
    public function code(string $code): static
    {
        return $this->state(fn (): array => [
            'code'   => $code,
            'name'   => $code.' Currency',
            'symbol' => $code,
        ]);
    }

    public function fiat(): static
    {
        return $this->state(fn (): array => ['type' => CurrencyType::FIAT]);
    }

    public function crypto(): static
    {
        return $this->state(fn (): array => ['type' => CurrencyType::CRYPTO]);
    }

    /**
     * Mark as the site base currency. The base currency always has a rate of 1
     * because every other rate is expressed as "1 base buys N of this currency".
     */
    public function asDefault(): static
    {
        return $this->state(fn (): array => [
            'default'       => true,
            'status'        => true,
            'auto_wallet'   => true,
            'exchange_rate' => 1,
        ]);
    }

    /**
     * Set the stored exchange rate. Accepts a string so high-precision crypto
     * rates such as '0.0000094118' survive without float rounding.
     */
    public function rate(string|int|float $rate): static
    {
        return $this->state(fn (): array => ['exchange_rate' => $rate]);
    }

    public function liveRate(): static
    {
        return $this->state(fn (): array => ['rate_live' => true]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }

    /**
     * Attach active currency roles after creation.
     *
     * Pass role names for defaults, or a role name => attribute-overrides map:
     *   ->withRoles([CurrencyRole::EXCHANGE])
     *   ->withRoles([CurrencyRole::EXCHANGE => ['fee' => 2, 'fee_type' => 'percent']])
     *
     * @param array<int|string, string|array<string, mixed>> $roles
     */
    public function withRoles(array $roles = []): static
    {
        $roles = $roles === [] ? CurrencyRoleName::getRoles() : $roles;

        return $this->afterCreating(function (Currency $currency) use ($roles): void {
            foreach ($roles as $key => $value) {
                $roleName  = is_int($key) ? $value : $key;
                $overrides = is_int($key) ? [] : $value;

                $currency->roles()->create(array_merge([
                    'role_name' => $roleName,
                    'min_limit' => 0,
                    'max_limit' => 1000000,
                    'fee_type'  => 'fixed',
                    'fee'       => 0,
                    'is_active' => true,
                ], $overrides));
            }
        });
    }
}
