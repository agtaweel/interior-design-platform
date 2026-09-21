<?php

namespace Tests\Feature\Organizations;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMemberTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsMember(Organization $organization, array $permissions, string $status = 'active'): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => $status,
        ]);

        $token = $user->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");

        return $user;
    }

    public function test_admin_can_invite_a_new_user_by_email(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization, [Permissions::MANAGE_MEMBERS => true]);
        $designerRole = Role::factory()->create(['organization_id' => null, 'name' => 'Designer']);

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
            'email' => 'newhire@example.com',
            'name' => 'New Hire',
            'role_id' => $designerRole->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.email', 'newhire@example.com')
            ->assertJsonPath('data.status', 'invited');

        $this->assertDatabaseHas('users', ['email' => 'newhire@example.com', 'status' => 'invited']);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'role_id' => $designerRole->id,
            'status' => 'invited',
        ]);

        // Wired audit logging: inviting a member should leave a trail.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'OrganizationMember',
            'action' => 'created',
        ]);
    }

    public function test_inviting_an_existing_active_member_again_is_a_conflict(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization, [Permissions::MANAGE_MEMBERS => true]);
        $role = Role::factory()->create(['organization_id' => null]);
        $existingUser = User::factory()->create(['email' => 'already@example.com']);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $existingUser->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
            'email' => 'already@example.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'member_already_exists');
    }

    public function test_non_admin_member_cannot_invite(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization, [Permissions::MANAGE_MEMBERS => false]);
        $role = Role::factory()->create(['organization_id' => null]);

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
            'email' => 'newhire@example.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_inactive_membership_cannot_invite_even_with_admin_role(): void
    {
        $organization = Organization::factory()->create();
        // Suspended membership: middleware should reject before the permission check ever runs.
        $this->actingAsMember($organization, [Permissions::MANAGE_MEMBERS => true], status: 'suspended');
        $role = Role::factory()->create(['organization_id' => null]);

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
            'email' => 'newhire@example.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'no_active_membership');
    }

    public function test_admin_can_change_a_members_role_and_status(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization, [Permissions::MANAGE_MEMBERS => true]);
        $oldRole = Role::factory()->create(['organization_id' => null]);
        $newRole = Role::factory()->create(['organization_id' => null]);
        $target = OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'role_id' => $oldRole->id,
            'status' => 'invited',
        ]);

        $response = $this->patchJson("/api/v1/organizations/{$organization->id}/members/{$target->id}", [
            'role_id' => $newRole->id,
            'status' => 'active',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role.id', $newRole->id)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'OrganizationMember',
            'entity_id' => $target->id,
            'action' => 'updated',
        ]);
    }

    public function test_admin_cannot_assign_a_role_belonging_to_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAsMember($organization, [Permissions::MANAGE_MEMBERS => true]);

        $otherOrganization = Organization::factory()->create();
        $foreignRole = Role::factory()->create(['organization_id' => $otherOrganization->id]);

        $response = $this->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
            'email' => 'newhire@example.com',
            'role_id' => $foreignRole->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'invalid_role');
    }
}
