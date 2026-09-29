<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'description' => fake()->words(3, true),
            'unit' => fake()->randomElement(['m2', 'pcs', 'lm', 'kg']),
            'quantity' => fake()->randomFloat(2, 1, 100),
            'quoted_unit_price' => fake()->randomFloat(2, 50, 2000),
            'received_quantity' => 0,
            'actual_unit_price' => null,
        ];
    }
}
