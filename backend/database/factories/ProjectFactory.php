<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Resolve organization_id from the (possibly newly created) client so the two
            // never disagree — a project must live in the same tenant as its client.
            'client_id' => Client::factory(),
            'organization_id' => function (array $attributes) {
                return Client::find($attributes['client_id'])?->organization_id;
            },
            'property_id' => null,
            'code' => 'PRJ-'.fake()->unique()->numerify('#####'),
            'name' => fake()->words(3, true).' project',
            'status' => 'draft',
            'start_date' => fake()->optional()->dateTimeBetween('-1 month', '+1 month'),
            'target_end_date' => fake()->optional()->dateTimeBetween('+2 months', '+8 months'),
            'responsible_user_id' => null,
        ];
    }
}
