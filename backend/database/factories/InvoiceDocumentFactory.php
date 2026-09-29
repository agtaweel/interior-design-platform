<?php

namespace Database\Factories;

use App\Models\InvoiceDocument;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceDocument>
 */
class InvoiceDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'supplier_id' => null,
            'invoice_number' => fake()->unique()->numerify('INV-#####'),
            'invoice_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 50000),
            'vat_amount' => null,
            'currency' => 'EGP',
            'payment_status' => 'unpaid',
            'file_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'uploaded_by' => User::factory(),
        ];
    }
}
