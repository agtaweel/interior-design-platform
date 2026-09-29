<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company(),
            'category' => fake()->randomElement(['Flooring', 'Electrical', 'Plumbing', 'Paint', 'Kitchen']),
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('+201#########'),
            'email' => fake()->unique()->companyEmail(),
            'payment_terms' => fake()->randomElement(['Net 15', 'Net 30', 'Cash on delivery']),
            'notes' => null,
        ];
    }
}
