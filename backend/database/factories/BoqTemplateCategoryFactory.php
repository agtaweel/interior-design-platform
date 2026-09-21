<?php

namespace Database\Factories;

use App\Models\BoqTemplateCategory;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqTemplateCategory>
 */
class BoqTemplateCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_id' => null,
            'name' => fake()->randomElement([
                'Flooring', 'Painting', 'Electrical', 'Plumbing', 'Carpentry',
                'Ceiling', 'Kitchen Cabinets', 'Doors & Windows', 'HVAC', 'Furniture',
            ]),
            'sort_order' => 0,
        ];
    }
}
