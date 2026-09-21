<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor_user_id' => null,
            'entity_type' => 'Project',
            'entity_id' => 1,
            'action' => 'created',
            'before_json' => null,
            'after_json' => [],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
