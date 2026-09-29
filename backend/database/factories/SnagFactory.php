<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Snag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Snag>
 */
class SnagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'description' => fake()->sentence(6),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'owner_user_id' => null,
            'due_date' => null,
            'status' => 'open',
            'is_mandatory' => true,
            'resolution_notes' => null,
            'closed_at' => null,
        ];
    }
}
