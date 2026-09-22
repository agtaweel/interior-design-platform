<?php

namespace Database\Factories;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeOrderItem>
 *
 * Default state is an 'add' line (the simplest case: no prior BOQ item/price involved). Use
 * the remove()/modify() states below for the other two actions — each keeps line_delta
 * correctly signed per PROJECT_CONTEXT.md's Sprint 7 formulas:
 *   add    -> quantity * new_unit_price               (positive)
 *   remove -> -(quantity * old_unit_price)             (negative)
 *   modify -> quantity * (new_unit_price - old_unit_price)  (sign follows price direction)
 */
class ChangeOrderItemFactory extends Factory
{
    /**
     * Define the model's default state: an 'add' line item.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 50);
        $newUnitPrice = fake()->randomFloat(2, 100, 3500);

        return [
            'change_order_id' => ChangeOrder::factory(),
            'action' => 'add',
            'boq_item_id' => null,
            'description' => fake()->randomElement([
                'Porcelain Tile 60x60 (upgraded grade)', 'Additional Wall Socket Installation',
                'Extra PVC Pipe Fitting', 'Built-in Wardrobe - Extension Unit',
                'Additional Gypsum Board Ceiling Section', 'Extra Kitchen Cabinet Unit',
            ]),
            'quantity' => $quantity,
            'unit' => fake()->randomElement(['m2', 'm', 'pcs', 'unit', 'lm']),
            'old_unit_price' => null,
            'new_unit_price' => $newUnitPrice,
            'line_delta' => bcmul((string) $quantity, (string) $newUnitPrice, 2),
        ];
    }

    /**
     * A 'remove' line: an existing BOQ item is being dropped from scope. old_unit_price is the
     * price the item was carrying; new_unit_price is null (nothing new is being priced).
     * line_delta = -(quantity * old_unit_price), always negative.
     */
    public function remove(): static
    {
        return $this->state(function (array $attributes): array {
            $quantity = $attributes['quantity'] ?? fake()->randomFloat(2, 1, 50);
            $oldUnitPrice = fake()->randomFloat(2, 100, 3500);

            return [
                'action' => 'remove',
                'quantity' => $quantity,
                'old_unit_price' => $oldUnitPrice,
                'new_unit_price' => null,
                'line_delta' => bcmul('-1', bcmul((string) $quantity, (string) $oldUnitPrice, 2), 2),
            ];
        });
    }

    /**
     * A 'modify' line: an existing BOQ item's unit price is changing (quantity itself is never
     * touched by 'modify', per PROJECT_CONTEXT.md's scope simplification — a real quantity
     * change is modeled as remove+add instead). line_delta = quantity * (new - old), so it can
     * land either sign depending on whether the price went up or down.
     */
    public function modify(): static
    {
        return $this->state(function (array $attributes): array {
            $quantity = $attributes['quantity'] ?? fake()->randomFloat(2, 1, 50);
            $oldUnitPrice = fake()->randomFloat(2, 100, 3500);
            $newUnitPrice = fake()->randomFloat(2, 100, 3500);

            return [
                'action' => 'modify',
                'quantity' => $quantity,
                'old_unit_price' => $oldUnitPrice,
                'new_unit_price' => $newUnitPrice,
                'line_delta' => bcmul((string) $quantity, bcsub((string) $newUnitPrice, (string) $oldUnitPrice, 2), 2),
            ];
        });
    }
}
