<?php

namespace Database\Factories;

use App\Models\BoqTemplateCategory;
use App\Models\BoqTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqTemplateItem>
 */
class BoqTemplateItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Resolve organization_id from the (possibly newly created) template category so
            // the two never disagree, same rationale as PropertyFactory/ProjectFactory.
            'category_id' => BoqTemplateCategory::factory(),
            'organization_id' => function (array $attributes) {
                return BoqTemplateCategory::find($attributes['category_id'])?->organization_id;
            },
            'name' => fake()->randomElement([
                'Porcelain Tile 60x60', 'Emulsion Paint - Two Coats', 'Wall Socket Installation',
                'PVC Pipe Fitting', 'Built-in Wardrobe', 'Gypsum Board Ceiling',
                'Kitchen Cabinet Unit', 'Aluminum Window', 'Split AC Unit', 'Sofa Set',
            ]),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement(['m2', 'm', 'pcs', 'unit', 'lm']),
            'material_unit_cost' => fake()->randomFloat(2, 50, 2000),
            'labor_unit_cost' => fake()->randomFloat(2, 20, 800),
            'other_unit_cost' => fake()->randomFloat(2, 0, 200),
            'client_unit_price' => fake()->randomFloat(2, 100, 3500),
            'notes' => null,
            'sort_order' => 0,
        ];
    }
}
