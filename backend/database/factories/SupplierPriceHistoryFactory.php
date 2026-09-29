<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\SupplierPriceHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPriceHistory>
 */
class SupplierPriceHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'supplier_id' => Supplier::factory(),
            'item_description' => fake()->words(3, true),
            'unit' => 'm2',
            'unit_price' => fake()->randomFloat(2, 50, 2000),
            'source' => 'quoted',
            'purchase_order_item_id' => null,
            'recorded_at' => now(),
        ];
    }
}
