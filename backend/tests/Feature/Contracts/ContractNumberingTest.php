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
 * ContractService::generateContractNo() (PROJECT_CONTEXT.md Sprint 5): "CTR-00001" style,
 * mirroring ProjectController::generateProjectCode()'s "PRJ-00001" pattern, computed as
 * `Contract::query()->count() + 1` — GLOBAL across all tenants (contracts have no
 * organization_id column of their own to scope the count by, per that method's docblock), not
 * per-organization like projects.code. This test confirms the exact generated pattern/format
 * and that two contracts created in sequence get distinct, correctly-incrementing numbers.
 */
class ContractNumberingTest extends TestCase
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

    private function convertNewApprovedProposal(array $headers, Project $project)
    {
        // Each call targets a DIFFERENT project (see call sites) purely to sidestep the
        // unrelated unique(project_id, version_no) constraint on proposal_versions — contract_no
        // generation itself is global across all tenants/projects (ContractService::
        // generateContractNo()'s docblock: "counts across all tenants"), so using distinct
        // projects here doesn't weaken what this test is actually pinning.
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        return $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
    }

    public function test_two_contracts_created_in_sequence_get_distinct_correctly_incrementing_contract_numbers(): void
    {
        $this->assertDatabaseCount('contracts', 0);

        $organization = Organization::factory()->create();
        $projectOne = $this->projectIn($organization);
        $projectTwo = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);

        $first = $this->convertNewApprovedProposal($headers, $projectOne);
        $second = $this->convertNewApprovedProposal($headers, $projectTwo);

        $firstNo = $first->json('data.contract_no');
        $secondNo = $second->json('data.contract_no');

        $this->assertMatchesRegularExpression('/^CTR-\d{5}$/', $firstNo);
        $this->assertMatchesRegularExpression('/^CTR-\d{5}$/', $secondNo);
        $this->assertNotSame($firstNo, $secondNo);

        // Exact values, since the counting DB is fresh (RefreshDatabase, sqlite :memory:) —
        // this pins the format precisely, not just "they differ".
        $this->assertSame('CTR-00001', $firstNo);
        $this->assertSame('CTR-00002', $secondNo);

        $firstInt = (int) substr($firstNo, 4);
        $secondInt = (int) substr($secondNo, 4);
        $this->assertSame($firstInt + 1, $secondInt);

        $this->assertDatabaseCount('contracts', 2);
        $this->assertSame(2, Contract::query()->distinct()->count('contract_no'));
    }
}
