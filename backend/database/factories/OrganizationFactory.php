<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'legal_name' => fake()->company().' '.fake()->companySuffix(),
            'logo_url' => null,
            'phone' => fake()->numerify('+201#########'),
            'email' => fake()->unique()->companyEmail(),
            'currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'settings_json' => [],
        ];
    }
}
