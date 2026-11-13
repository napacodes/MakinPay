<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AddonStatus;
use App\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Addon>
 */
class AddonFactory extends Factory
{
    protected $model = Addon::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'slug'         => Str::slug($name),
            'name'         => $name,
            'version'      => '1.0.0',
            'status'       => AddonStatus::INSTALLED,
            'last_error'   => null,
            'meta'         => [],
            'installed_at' => now(),
            'enabled_at'   => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status'     => AddonStatus::ACTIVE,
            'enabled_at' => now(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => AddonStatus::DISABLED]);
    }

    public function failed(string $error = 'Migration failed.'): static
    {
        return $this->state(fn (): array => [
            'status'     => AddonStatus::FAILED,
            'last_error' => $error,
        ]);
    }
}
