<?php

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Property;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke coverage for the Sprint 1 Clients/Properties/Projects REST surface added by
 * backend-api-engineer: proves basic CRUD works end to end and that it does not leak across
 * tenants. Deep edge-case coverage (every validation rule, every permission combination) is
 * qa-test-engineer's job — this only has to prove the endpoints don't crash and respect tenant
 * scoping, per the working agreement in .claude/agents/backend-api-engineer.md.
 */
class ClientPropertyProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPermissions(Organization $organization, array $permissions, string $status = 'active'): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => $status,
        ]);

        return $user;
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_CLIENTS => true,
            Permissions::MANAGE_PROJECTS => true,
        ]);
    }

    public function test_full_client_property_project_lifecycle_via_the_api(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);

        // --- Clients ---
        $createClient = $this->withHeaders($headers)->postJson('/api/v1/clients', [
            'name' => 'Nour Interiors Client',
            'phone' => '+201001234567',
            'email' => 'nour@example.com',
        ]);
        $createClient->assertStatus(201)->assertJsonPath('data.name', 'Nour Interiors Client');
        $clientId = $createClient->json('data.id');
        $this->assertDatabaseHas('clients', ['id' => $clientId, 'organization_id' => $organization->id]);

        $listClients = $this->withHeaders($headers)->getJson('/api/v1/clients?q=Nour');
        $listClients->assertStatus(200)->assertJsonPath('data.0.id', $clientId);

        $showClient = $this->withHeaders($headers)->getJson("/api/v1/clients/{$clientId}");
        $showClient->assertStatus(200)
            ->assertJsonPath('data.id', $clientId)
            ->assertJsonPath('data.properties_count', 0)
            ->assertJsonPath('data.projects_count', 0);

        $updateClient = $this->withHeaders($headers)->patchJson("/api/v1/clients/{$clientId}", [
            'notes' => 'Prefers WhatsApp contact.',
        ]);
        $updateClient->assertStatus(200)->assertJsonPath('data.notes', 'Prefers WhatsApp contact.');

        // --- Properties ---
        $createProperty = $this->withHeaders($headers)->postJson("/api/v1/clients/{$clientId}/properties", [
            'type' => 'apartment',
            'compound' => 'Mivida',
            'area_m2' => 180.5,
            'bedrooms' => 3,
            'bathrooms' => 2,
        ]);
        $createProperty->assertStatus(201)->assertJsonPath('data.type', 'apartment');
        $propertyId = $createProperty->json('data.id');
        $this->assertDatabaseHas('properties', [
            'id' => $propertyId,
            'client_id' => $clientId,
            'organization_id' => $organization->id,
        ]);

        $showProperty = $this->withHeaders($headers)->getJson("/api/v1/properties/{$propertyId}");
        $showProperty->assertStatus(200)->assertJsonPath('data.client.id', $clientId);

        // --- Projects ---
        $createProject = $this->withHeaders($headers)->postJson('/api/v1/projects', [
            'client_id' => $clientId,
            'property_id' => $propertyId,
            'name' => 'Mivida Apartment Fit-Out',
        ]);
        $createProject->assertStatus(201)
            ->assertJsonPath('data.name', 'Mivida Apartment Fit-Out')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.financials.value', 0)
            ->assertJsonPath('data.financials.collected', 0)
            ->assertJsonPath('data.financials.outstanding', 0)
            ->assertJsonPath('data.financials.actual_cost', 0)
            ->assertJsonPath('data.financials.gross_profit', null);
        $projectId = $createProject->json('data.id');
        $this->assertNotEmpty($createProject->json('data.code'));

        // property must belong to the same client
        $otherClient = Client::factory()->create(['organization_id' => $organization->id]);
        $rejectedProject = $this->withHeaders($headers)->postJson('/api/v1/projects', [
            'client_id' => $otherClient->id,
            'property_id' => $propertyId,
            'name' => 'Mismatched Property Project',
        ]);
        $rejectedProject->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $listProjects = $this->withHeaders($headers)->getJson('/api/v1/projects?status=draft');
        $listProjects->assertStatus(200)->assertJsonPath('data.0.id', $projectId);

        $showProject = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectId}");
        $showProject->assertStatus(200)->assertJsonPath('data.id', $projectId);

        $updateProject = $this->withHeaders($headers)->patchJson("/api/v1/projects/{$projectId}", [
            'status' => 'active',
        ]);
        $updateProject->assertStatus(200)->assertJsonPath('data.status', 'active');

        // --- Project members ---
        $teammate = $this->memberWithPermissions($organization, []);
        $addMember = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectId}/members", [
            'user_id' => $teammate->id,
            'role' => 'lead_designer',
        ]);
        $addMember->assertStatus(201)->assertJsonPath('data.user.id', $teammate->id);
        $this->assertDatabaseHas('project_members', ['project_id' => $projectId, 'user_id' => $teammate->id]);

        // duplicate assignment is rejected as a conflict, not a crash
        $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectId}/members", [
            'user_id' => $teammate->id,
            'role' => 'lead_designer',
        ])->assertStatus(409)->assertJsonPath('error.code', 'member_already_assigned');

        // a user outside the organization cannot be assigned
        $outsider = User::factory()->create();
        $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectId}/members", [
            'user_id' => $outsider->id,
            'role' => 'viewer',
        ])->assertStatus(422);

        // --- Project services ---
        $addService = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectId}/services", [
            'service_type' => 'design',
            'pricing_method' => 'fixed',
            'price' => 50000,
        ]);
        $addService->assertStatus(201)->assertJsonPath('data.service_type', 'design');
        $this->assertDatabaseHas('project_services', ['project_id' => $projectId, 'service_type' => 'design']);
    }

    public function test_a_user_without_manage_permissions_cannot_create_a_client_or_project(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/clients', ['name' => 'Should Fail'])
            ->assertStatus(403);

        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/projects', ['client_id' => $client->id, 'name' => 'Should Fail'])
            ->assertStatus(403);
    }

    public function test_client_and_project_endpoints_are_tenant_isolated(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $this->fullAccessUser($orgB);

        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        $propertyB = Property::factory()->create(['client_id' => $clientB->id, 'organization_id' => $orgB->id]);
        $projectB = Project::factory()->create(['client_id' => $clientB->id, 'organization_id' => $orgB->id]);

        $headersA = $this->authHeader($userA);

        // Cannot read another organization's records by id.
        $this->withHeaders($headersA)->getJson("/api/v1/clients/{$clientB->id}")->assertStatus(404);
        $this->withHeaders($headersA)->getJson("/api/v1/properties/{$propertyB->id}")->assertStatus(404);
        $this->withHeaders($headersA)->getJson("/api/v1/projects/{$projectB->id}")->assertStatus(404);

        // Cannot update another organization's client.
        $this->withHeaders($headersA)
            ->patchJson("/api/v1/clients/{$clientB->id}", ['name' => 'Hacked'])
            ->assertStatus(404);
        $this->assertSame($clientB->name, $clientB->fresh()->name);

        // Index never lists organization B's client.
        $this->withHeaders($headersA)
            ->getJson('/api/v1/clients')
            ->assertStatus(200)
            ->assertJsonMissing(['id' => $clientB->id]);

        // Cannot create a project against another organization's client id.
        $this->withHeaders($headersA)
            ->postJson('/api/v1/projects', ['client_id' => $clientB->id, 'name' => 'Cross-tenant Project'])
            ->assertStatus(422);
    }
}
