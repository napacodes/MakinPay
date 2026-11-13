<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active, admin-made category. The six system categories are reference
 * data seeded by migration; tests that need one read it by code.
 *
 * @extends Factory<BillCategory>
 */
class BillCategoryFactory extends Factory
{
    protected $model = BillCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->slug(2);

        return [
            'code'        => $code,
            'name'        => ucwords(str_replace('-', ' ', $code)),
            'icon'        => 'bill-other',
            'description' => null,
            'is_system'   => false,
            'status'      => true,
            'sort_order'  => 100,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}
