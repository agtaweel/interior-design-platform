<?php

namespace Database\Factories;

use App\Models\BoqTemplateApplication;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqTemplateApplication>
 */
class BoqTemplateApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_id' => Project::factory(),
            'template_id' => null,
            'template_version_id' => null,
            'applied_by' => null,
            'item_count' => 0,
        ];
    }
}
