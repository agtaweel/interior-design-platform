<?php

namespace Database\Factories;

use App\Models\Handover;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Handover>
 */
class HandoverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'approved_by_user_id' => User::factory(),
            'handover_date' => now()->toDateString(),
            'warranty_period_months' => 12,
            'warranty_notes' => null,
            'notes' => null,
        ];
    }
}
