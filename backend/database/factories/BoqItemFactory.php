<?php

namespace Database\Factories;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqItem>
 */
class BoqItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Resolve project_id from the (possibly newly created) category so the two never
            // disagree — a BOQ item must live in the same project as its category.
            'category_id' => BoqCategory::factory(),
            'project_id' => function (array $attributes) {
                return BoqCategory::find($attributes['category_id'])?->project_id;
            },
            'room_id' => null,
            'name' => fake()->randomElement([
                'Porcelain Tile 60x60', 'Emulsion Paint - Two Coats', 'Wall Socket Installation',
                'PVC Pipe Fitting', 'Built-in Wardrobe', 'Gypsum Board Ceiling',
                'Kitchen Cabinet Unit', 'Aluminum Window', 'Split AC Unit', 'Sofa Set',
            ]),
            'description' => fake()->optional()->sentence(),
            'quantity' => fake()->randomFloat(2, 1, 50),
            'unit' => fake()->randomElement(['m2', 'm', 'pcs', 'unit', 'lm']),
            'material_unit_cost' => fake()->randomFloat(2, 50, 2000),
            'labor_unit_cost' => fake()->randomFloat(2, 20, 800),
            'other_unit_cost' => fake()->randomFloat(2, 0, 200),
            'client_unit_price' => fake()->randomFloat(2, 100, 3500),
            'supplier_id' => null,
            'notes' => null,
            'sort_order' => 0,
            'archived_at' => null,
        ];
    }
}
