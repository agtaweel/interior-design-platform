<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
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
            // never disagree — a property must live in the same tenant as its client.
            'client_id' => Client::factory(),
            'organization_id' => function (array $attributes) {
                return Client::find($attributes['client_id'])?->organization_id;
            },
            'type' => fake()->randomElement(['apartment', 'villa', 'office', 'retail', 'other']),
            'compound' => fake()->optional()->streetName(),
            'address' => fake()->address(),
            'area_m2' => fake()->randomFloat(2, 60, 600),
            'bedrooms' => fake()->numberBetween(1, 6),
            'bathrooms' => fake()->numberBetween(1, 4),
            'metadata_json' => [],
        ];
    }
}
