<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SiteReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteReport>
 */
class SiteReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'reported_by_user_id' => User::factory(),
            'report_date' => now()->toDateString(),
            'work_done' => fake()->paragraph(),
            'issues' => null,
            'decisions' => null,
        ];
    }
}
