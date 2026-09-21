<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectService>
 */
class ProjectServiceFactory extends Factory
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
            'service_type' => fake()->randomElement(['design', 'execution', 'supervision', 'consultation']),
            'pricing_method' => fake()->randomElement(['fixed', 'per_m2', 'per_room', 'percentage']),
            'price' => fake()->randomFloat(2, 1000, 200000),
            'metadata_json' => [],
        ];
    }
}
