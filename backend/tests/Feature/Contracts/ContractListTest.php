<?php

namespace Tests\Feature\Contracts;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /projects/{id}/contracts (ContractController::index(), PROJECT_CONTEXT.md Sprint 5 —
 * added after a frontend-discovered gap, per the task brief). Mirrors
 * ProposalVersionController::index()'s shape: manual project lookup, read access needs only an
 * active membership, plain array under `data` ordered by created_at. Tenant isolation for this
 * endpoint is covered separately in ContractTenantIsolationTest; this class covers the
 * endpoint's own empty/non-empty response shape.
 */
class ContractListTest extends TestCase
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

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    public function test_a_project_with_no_contracts_returns_an_empty_array(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/contracts");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_a_project_with_one_contract_returns_it_with_expected_fields(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        $created = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        $contractId = $created->json('data.id');

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/contracts");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($contractId, $data[0]['id']);
        $this->assertSame($project->id, $data[0]['project_id']);
        $this->assertSame('active', $data[0]['status']);
        $this->assertArrayHasKey('contract_no', $data[0]);
        $this->assertArrayHasKey('contract_value', $data[0]);
    }

    public function test_a_nonexistent_project_returns_404_for_the_list_endpoint(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/projects/999999/contracts')
            ->assertStatus(404);
    }
}
