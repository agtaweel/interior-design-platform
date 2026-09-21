<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMember>
 */
class OrganizationMemberFactory extends Factory
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
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'status' => 'active',
        ];
    }
}
