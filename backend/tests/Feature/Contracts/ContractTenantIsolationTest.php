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
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Extends the tenant-isolation guarantee (tests/Feature/Tenancy/TenantIsolationTest.php,
 * tests/Feature/Proposals/ProposalTenantIsolationTest.php) to Sprint 5's contracts surface.
 * Contract carries no organization_id of its own (scoped indirectly via
 * project_id -> projects.organization_id, see Contract model docblock) — this is exactly the
 * shape ProposalTenantIsolationTest already proved matters most: org A must never be able to
 * read/write org B's contract even by directly guessing a numeric id, on every one of
 * index/show/update/pdf/from-proposal.
 */
class ContractTenantIsolationTest extends TestCase
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

    /** Builds org B's contract directly (not via HTTP, since we're org A in these tests). */
    private function contractIn(Project $project): Contract
    {
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        return Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '321000.00',
            // Pinned explicitly (rather than left to the factory's fake()->optional() dates)
            // so the "PATCH did not apply" assertion below has a known starting value.
            'start_date' => null,
            'end_date' => null,
        ]);
    }

    public function test_cannot_list_another_organizations_project_contracts(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $this->contractIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectB->id}/contracts")
            ->assertStatus(404);
    }

    public function test_cannot_convert_a_proposal_belonging_to_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $proposalB = ProposalVersion::factory()->approved()->create(['project_id' => $projectB->id]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/contracts/from-proposal/{$proposalB->id}")
            ->assertStatus(404);

        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_cannot_view_another_organizations_contract_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $contractB = $this->contractIn($projectB);

        $response = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/contracts/{$contractB->id}");

        $response->assertStatus(404);
        $this->assertStringNotContainsString('321000', $response->getContent());
    }

    public function test_cannot_patch_another_organizations_contract_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $contractB = $this->contractIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->patchJson("/api/v1/contracts/{$contractB->id}", ['start_date' => '2026-01-01'])
            ->assertStatus(404);

        $this->assertNull($contractB->fresh()->start_date);
    }

    public function test_cannot_download_another_organizations_contract_pdf_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $contractB = $this->contractIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/contracts/{$contractB->id}/pdf")
            ->assertStatus(404);
    }

    public function test_org_a_user_sees_only_org_as_own_contracts_when_both_orgs_have_them(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectA = $this->projectIn($orgA);
        $projectB = $this->projectIn($orgB);
        $proposalA = ProposalVersion::factory()->approved()->create(['project_id' => $projectA->id]);
        $this->contractIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectA->id}/contracts/from-proposal/{$proposalA->id}")
            ->assertStatus(201);

        // Auth::forgetGuards() precaution matching ProposalTenantIsolationTest's documented
        // rationale (Sanctum's guard memoizes per-test-method) — not strictly needed here since
        // only userA authenticates, kept for consistency.
        Auth::forgetGuards();

        $index = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectA->id}/contracts")
            ->assertStatus(200);

        $this->assertCount(1, $index->json('data'));
        $this->assertDatabaseCount('contracts', 2);
    }
}
