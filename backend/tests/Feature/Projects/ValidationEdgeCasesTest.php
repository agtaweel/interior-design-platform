<?php

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validation edge cases for the Clients/Properties/Projects write endpoints that
 * ClientPropertyProjectApiTest's happy-path lifecycle test doesn't exercise: missing required
 * fields, invalid enum values, and cross-organization id references smuggled into a request
 * body (as opposed to the route, which OrganizationScope already protects).
 */
class ValidationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function fullAccessUser(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [
            Permissions::MANAGE_CLIENTS => true,
            Permissions::MANAGE_PROJECTS => true,
        ]]);
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

    public function test_creating_a_client_without_a_name_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/clients', ['phone' => '+201001234567'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_creating_a_client_with_an_invalid_email_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/clients', ['name' => 'Bad Email Client', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_creating_a_property_without_a_type_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/clients/{$client->id}/properties", [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['type']]]);
    }

    public function test_creating_a_property_with_an_invalid_type_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/clients/{$client->id}/properties", ['type' => 'castle'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['type']]]);
    }

    public function test_creating_a_property_under_another_organizations_client_id_is_not_found_not_a_leak(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);

        // The client id exists, just not in userA's organization — OrganizationScope makes
        // Client::find() return null for it inside PropertyController, so this must 404, not
        // 422/403, and must never create a property.
        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/clients/{$clientB->id}/properties", ['type' => 'villa'])
            ->assertStatus(404);

        $this->assertDatabaseMissing('properties', ['client_id' => $clientB->id, 'organization_id' => $orgA->id]);
    }

    public function test_creating_a_project_without_a_name_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/projects', ['client_id' => $client->id])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_creating_a_project_without_a_client_id_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/projects', ['name' => 'No Client Project'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['client_id']]]);
    }

    public function test_creating_a_project_with_another_organizations_client_id_fails_validation_not_tenant_mismatch(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);

        // client_id is checked via a scoped Rule::exists() in StoreProjectRequest, so this
        // surfaces as an ordinary 422 validation failure, not a 403/404 — the request never
        // gets far enough to resolve a real cross-tenant record.
        $this->withHeaders($this->authHeader($userA))
            ->postJson('/api/v1/projects', ['client_id' => $clientB->id, 'name' => 'Cross-tenant'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['client_id']]]);
    }

    public function test_creating_a_project_with_another_organizations_property_id_fails_validation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $clientA = Client::factory()->create(['organization_id' => $orgA->id]);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        $propertyB = Property::factory()->create(['organization_id' => $orgB->id, 'client_id' => $clientB->id]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson('/api/v1/projects', [
                'client_id' => $clientA->id,
                'property_id' => $propertyB->id,
                'name' => 'Cross-tenant Property',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['property_id']]]);
    }

    public function test_updating_a_project_with_an_invalid_status_fails_validation(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/projects/{$project->id}", ['status' => 'not_a_real_status'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['status']]]);
    }

    public function test_updating_a_project_property_id_must_belong_to_the_projects_existing_client(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $otherClient = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $foreignProperty = Property::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $otherClient->id,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/projects/{$project->id}", ['property_id' => $foreignProperty->id])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['property_id']]]);
    }

    public function test_a_user_without_manage_clients_cannot_create_a_property(): void
    {
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [Permissions::MANAGE_CLIENTS => false]]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/clients/{$client->id}/properties", ['type' => 'villa'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('properties', ['client_id' => $client->id]);
    }
}
