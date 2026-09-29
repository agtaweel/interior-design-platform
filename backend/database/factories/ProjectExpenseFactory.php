<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectExpense>
 */
class ProjectExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes) {
                return Project::find($attributes['project_id'])?->organization_id ?? \App\Models\Organization::factory();
            },
            'project_id' => Project::factory(),
            'supplier_id' => null,
            'category' => fake()->randomElement(['material', 'labor', 'subcontractor', 'other']),
            'description' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 100, 50000),
            'expense_date' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'notes' => null,
        ];
    }
}
