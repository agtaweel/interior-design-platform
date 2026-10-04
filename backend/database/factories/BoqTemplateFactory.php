<?php

namespace Database\Factories;

use App\Models\BoqTemplate;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqTemplate>
 */
class BoqTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => fake()->unique()->lexify('tpl-????'),
            'name' => fake()->randomElement(['Full Apartment Finishing', 'Bathroom Renovation', 'Kitchen Renovation']),
            'name_en' => null,
            'name_ar' => null,
            'description' => null,
            'template_type' => BoqTemplate::TYPE_CUSTOM,
            'project_type' => null,
            'finishing_level' => null,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 0,
            'active_version_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /** A global/system template — see migration docblock for what null organization_id means. */
    public function system(): static
    {
        return $this->state(fn () => ['organization_id' => null, 'is_system' => true]);
    }
}
