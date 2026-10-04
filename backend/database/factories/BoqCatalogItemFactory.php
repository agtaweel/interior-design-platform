<?php

namespace Database\Factories;

use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqCatalogItem>
 */
class BoqCatalogItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Resolve organization_id from the (possibly newly created) category so the two
            // never disagree, same rationale as BoqTemplateItemFactory.
            'category_id' => BoqCatalogCategory::factory(),
            'organization_id' => function (array $attributes) {
                return BoqCatalogCategory::find($attributes['category_id'])?->organization_id;
            },
            'name' => fake()->randomElement([
                'Porcelain Tile 60x60', 'Lighting Point', 'Socket Point', 'Cold Water Point',
                'WC Installation', 'Wall Paint - Two Coats', 'Kitchen Cabinet Unit',
            ]),
            'name_en' => null,
            'name_ar' => null,
            'description' => fake()->optional()->sentence(),
            'description_en' => null,
            'description_ar' => null,
            'default_unit_id' => null,
            'default_material_unit_cost' => fake()->randomFloat(2, 50, 2000),
            'default_labor_unit_cost' => fake()->randomFloat(2, 20, 800),
            'default_other_unit_cost' => fake()->randomFloat(2, 0, 200),
            'default_client_unit_price' => fake()->randomFloat(2, 100, 3500),
            'is_active' => true,
            'sort_order' => 0,
            'created_by' => null,
        ];
    }

    /** A global/system catalog item — see migration docblock for what null organization_id means. */
    public function system(): static
    {
        return $this->state(fn () => ['organization_id' => null]);
    }
}
