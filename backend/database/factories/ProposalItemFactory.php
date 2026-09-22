<?php

namespace Database\Factories;

use App\Models\ProposalItem;
use App\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalItem>
 */
class ProposalItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 50);
        $unitPrice = fake()->randomFloat(2, 100, 3500);

        return [
            'proposal_version_id' => ProposalVersion::factory(),
            'source_boq_item_id' => null,
            'description' => fake()->randomElement([
                'Porcelain Tile 60x60', 'Emulsion Paint - Two Coats', 'Wall Socket Installation',
                'PVC Pipe Fitting', 'Built-in Wardrobe', 'Gypsum Board Ceiling',
                'Kitchen Cabinet Unit', 'Aluminum Window', 'Split AC Unit', 'Sofa Set',
            ]),
            'quantity' => $quantity,
            'unit' => fake()->randomElement(['m2', 'm', 'pcs', 'unit', 'lm']),
            'unit_price' => $unitPrice,
            'line_total' => bcmul((string) $quantity, (string) $unitPrice, 2),
        ];
    }
}
