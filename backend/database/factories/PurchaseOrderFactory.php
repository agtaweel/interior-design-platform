<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'supplier_id' => Supplier::factory(),
            'po_number' => 'PO-'.fake()->unique()->numerify('#####'),
            'status' => 'draft',
            'notes' => null,
            'sent_at' => null,
        ];
    }
}
