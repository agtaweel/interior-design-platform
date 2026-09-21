<?php

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * backend-api-engineer built POST /projects/{project}/members and
 * POST /projects/{project}/services; ClientPropertyProjectApiTest only smoke-tests the
 * happy path plus one conflict case for members. This file fills the gaps: permission
 * enforcement (both endpoints are gated by MANAGE_PROJECTS per AddProjectMemberRequest /
 * AddProjectServiceRequest), validation edge cases, and tenant isolation (a project id from
 * another organization must 404, not leak or crash).
 */
class ProjectMemberAndServiceTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPermissions(Organization $organization, array $permissions): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    // --- Authorization ---

    public function test_a_user_without_manage_projects_cannot_add_a_project_member(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, []);
        $teammate = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/members", ['user_id' => $teammate->id, 'role' => 'viewer'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id, 'user_id' => $teammate->id]);
    }

    public function test_a_user_without_manage_projects_cannot_add_a_project_service(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/services", [
                'service_type' => 'design', 'pricing_method' => 'fixed', 'price' => 1000,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('project_services', ['project_id' => $project->id]);
    }

    // --- Tenant isolation ---

    public function test_cannot_add_a_member_to_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_PROJECTS => true]);
        $projectB = $this->projectIn($orgB);
        $teammateInA = $this->memberWithPermissions($orgA, []);

        // Unlike most cross-tenant lookups in this codebase, this one surfaces as a 422 rather
        // than a 404: AddProjectMemberRequest::rules() resolves the project itself (scoped by
        // OrganizationScope to org A) to build the user_id exists-check, and per that class's
        // docblock, when the route's {project} doesn't resolve in the current tenant the
        // resulting organization_id is null, so the exists-check simply never matches — the
        // request fails FormRequest validation before the controller's own 404 branch ever
        // runs. Confirmed intentional (see AddProjectMemberRequest docblock), not treated as a
        // bug here.
        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/members", ['user_id' => $teammateInA->id, 'role' => 'viewer'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);

        $this->assertDatabaseMissing('project_members', ['project_id' => $projectB->id, 'user_id' => $teammateInA->id]);
    }

    public function test_cannot_add_a_service_to_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_PROJECTS => true]);
        $projectB = $this->projectIn($orgB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/services", [
                'service_type' => 'design', 'pricing_method' => 'fixed', 'price' => 1000,
            ])
            ->assertStatus(404);
    }

    // --- Validation edge cases ---

    public function test_adding_a_project_service_rejects_an_invalid_pricing_method(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/services", [
                'service_type' => 'design',
                'pricing_method' => 'not_a_real_method',
                'price' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['pricing_method']]]);
    }

    public function test_adding_a_project_service_rejects_a_negative_price(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/services", [
                'service_type' => 'design',
                'pricing_method' => 'fixed',
                'price' => -500,
            ])
            ->assertStatus(422);
    }

    public function test_adding_a_project_service_requires_service_type_and_price(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/services", [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['service_type', 'pricing_method', 'price']]]);
    }

    public function test_adding_a_project_member_requires_a_user_id_that_is_an_active_member_of_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);
        $suspendedTeammate = $this->memberWithPermissions($organization, []);
        OrganizationMember::where('user_id', $suspendedTeammate->id)->update(['status' => 'suspended']);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/members", [
                'user_id' => $suspendedTeammate->id,
                'role' => 'viewer',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);
    }

    public function test_adding_a_project_member_requires_a_role(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);
        $teammate = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/members", ['user_id' => $teammate->id])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['role']]]);
    }
}
