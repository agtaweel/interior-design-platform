<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
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
            'name' => fake()->name(),
            'phone' => fake()->numerify('+201#########'),
            'email' => fake()->unique()->safeEmail(),
            'source' => fake()->randomElement(['referral', 'website', 'whatsapp', 'walk_in', 'social', 'other']),
            'status' => 'new',
            'estimated_budget' => fake()->randomFloat(2, 20000, 2000000),
            'notes' => null,
            'owner_id' => null,
        ];
    }
}
