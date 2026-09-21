<?php

namespace Database\Factories;

use App\Models\BoqCategory;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqCategory>
 */
class BoqCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'name' => fake()->randomElement([
                'Flooring', 'Painting', 'Electrical', 'Plumbing', 'Carpentry',
                'Ceiling', 'Kitchen Cabinets', 'Doors & Windows', 'HVAC', 'Furniture',
            ]),
            'sort_order' => 0,
        ];
    }
}
