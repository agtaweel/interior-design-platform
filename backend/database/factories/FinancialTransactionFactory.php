<?php

namespace Database\Factories;

use App\Models\FinancialTransaction;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialTransaction>
 */
class FinancialTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'scope' => FinancialTransaction::SCOPE_CLIENT,
            'type' => FinancialTransaction::TYPE_CONTRACT_CHARGE,
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'currency' => 'EGP',
            'transaction_date' => now()->toDateString(),
            'status' => FinancialTransaction::STATUS_POSTED,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => now(),
        ];
    }
}
