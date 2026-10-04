<?php

namespace Database\Factories;

use App\Models\BoqCatalogCategory;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqCatalogCategory>
 */
class BoqCatalogCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_id' => null,
            'name' => fake()->randomElement(['Flooring', 'Electrical', 'Plumbing', 'Painting', 'Kitchen']),
            'name_en' => null,
            'name_ar' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** A global/system catalog category — see migration docblock for what null organization_id means. */
    public function system(): static
    {
        return $this->state(fn () => ['organization_id' => null]);
    }
}
