<?php

namespace Database\Factories;

use App\Models\BoqTemplate;
use App\Models\BoqTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqTemplateVersion>
 */
class BoqTemplateVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_id' => BoqTemplate::factory(),
            'version_number' => 1,
            'status' => BoqTemplateVersion::STATUS_DRAFT,
            'published_at' => null,
            'created_by' => null,
            'change_notes' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => BoqTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
