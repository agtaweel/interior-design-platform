<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationProfile>
 */
class OrganizationProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'description' => fake()->paragraph(),
            'services_offered' => ['Interior Design', 'Fit-Out'],
            'service_area' => fake()->city(),
            'is_marketplace_listed' => false,
        ];
    }

    public function listed(): static
    {
        return $this->state(fn () => ['is_marketplace_listed' => true]);
    }
}
