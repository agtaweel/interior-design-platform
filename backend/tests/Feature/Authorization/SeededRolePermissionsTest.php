<?php

namespace Tests\Feature\Authorization;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other Sprint 1 test files build synthetic Role::factory() permission sets to isolate
 * single permissions. This file instead runs against the REAL global template roles shipped by
 * database/seeders/RoleSeeder.php (Owner/Admin/Designer/Site Staff) to prove the actual
 * role-to-permission mapping a new organization ships with behaves as designed: a Designer can
 * manage clients/projects, a Site Staff member can only read. This is the "does a Designer vs
 * Site Staff role differ in permissions" check called out in the qa-test-engineer brief.
 */
class SeededRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function memberWithRole(Organization $organization, string $roleName): User
    {
        $role = Role::where('organization_id', null)->where('name', $roleName)->firstOrFail();
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

    public function test_designer_can_create_clients_and_projects(): void
    {
        $organization = Organization::factory()->create();
        $designer = $this->memberWithRole($organization, 'Designer');

        $createClient = $this->withHeaders($this->authHeader($designer))->postJson('/api/v1/clients', [
            'name' => 'Designer-created Client',
        ]);
        $createClient->assertStatus(201);

        $createProject = $this->withHeaders($this->authHeader($designer))->postJson('/api/v1/projects', [
            'client_id' => $createClient->json('data.id'),
            'name' => 'Designer-created Project',
        ]);
        $createProject->assertStatus(201);
    }

    public function test_site_staff_can_read_clients_and_projects_but_cannot_create_or_update_them(): void
    {
        $organization = Organization::factory()->create();
        $siteStaff = $this->memberWithRole($organization, 'Site Staff');
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        $headers = $this->authHeader($siteStaff);

        // Reads succeed — Sprint 1 has no read-level permission gate, only write gates.
        $this->withHeaders($headers)->getJson('/api/v1/clients')->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/clients/{$client->id}")->assertStatus(200);
        $this->withHeaders($headers)->getJson('/api/v1/projects')->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}")->assertStatus(200);

        // Writes are forbidden.
        $this->withHeaders($headers)->postJson('/api/v1/clients', ['name' => 'Should Fail'])
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->withHeaders($headers)->patchJson("/api/v1/clients/{$client->id}", ['name' => 'Should Fail'])
            ->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/v1/projects', ['client_id' => $client->id, 'name' => 'Should Fail'])
            ->assertStatus(403);
        $this->withHeaders($headers)->patchJson("/api/v1/projects/{$project->id}", ['status' => 'active'])
            ->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/v1/clients/{$client->id}/properties", ['type' => 'villa'])
            ->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/services", [
            'service_type' => 'design', 'pricing_method' => 'fixed', 'price' => 1000,
        ])->assertStatus(403);
    }

    public function test_site_staff_cannot_invite_members_but_admin_can(): void
    {
        $organization = Organization::factory()->create();
        $siteStaff = $this->memberWithRole($organization, 'Site Staff');
        $admin = $this->memberWithRole($organization, 'Admin');
        $designerRole = Role::where('organization_id', null)->where('name', 'Designer')->firstOrFail();

        $this->withHeaders($this->authHeader($siteStaff))
            ->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
                'email' => 'blocked@example.com',
                'role_id' => $designerRole->id,
            ])->assertStatus(403);

        // Laravel's sanctum RequestGuard caches its resolved user for the lifetime of the guard
        // instance, which persists across these sequential in-test HTTP calls (unlike real
        // usage, where every request boots a fresh application) — see the identical note in
        // AuthTest::test_logout_revokes_only_the_current_token(). Forgetting the guard forces
        // it to re-resolve the user from the admin's bearer token instead of returning the
        // already-resolved site-staff user from the previous call.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->authHeader($admin))
            ->postJson("/api/v1/organizations/{$organization->id}/members/invite", [
                'email' => 'allowed@example.com',
                'role_id' => $designerRole->id,
            ])->assertStatus(201);
    }

    public function test_owner_has_every_permission_including_manage_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberWithRole($organization, 'Owner');

        $role = Role::where('organization_id', null)->where('name', 'Owner')->firstOrFail();
        foreach (\App\Support\Authorization\Permissions::ALL as $permission) {
            $this->assertTrue((bool) ($role->permissions_json[$permission] ?? false), "Owner should hold {$permission}");
        }

        $this->withHeaders($this->authHeader($owner))
            ->postJson('/api/v1/clients', ['name' => 'Owner-created Client'])
            ->assertStatus(201);
    }
}
