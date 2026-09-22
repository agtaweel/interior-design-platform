<?php

namespace Tests\Feature\Contracts;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 5 -> POST /projects/{id}/contracts/from-proposal/{proposalId}
 * (ContractController::fromProposal()). Covers the state-machine half of the conversion
 * endpoint: only an 'approved' proposal version may convert, and a version may convert at
 * most once (proposal_version_id is DB-unique on contracts, per the ERD's "Approved Proposal
 * Version 1—0..1 Contract" relationship).
 *
 * Deliberately builds draft/sent/changes_requested/approved proposal versions directly via the
 * factory rather than driving the full send/OTP-approve HTTP flow — the conversion endpoint
 * only reads `status`, so this is a faithful and much cheaper way to exercise all four states
 * (the full OTP flow IS exercised in ContractValueIntegrityTest/ContractTermsSeedingTest below
 * for the one scenario that actually needs a *real* approved proposal born from a real BOQ).
 */
class ContractConversionTest extends TestCase
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

    private function convert(array $headers, Project $project, ProposalVersion $proposal)
    {
        return $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}");
    }

    public function test_converting_a_draft_proposal_fails_with_409_proposal_not_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->create(['project_id' => $project->id, 'status' => 'draft']);

        $response = $this->convert($this->authHeader($user), $project, $proposal);

        $response->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_NOT_APPROVED');
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_converting_a_sent_proposal_fails_with_409_proposal_not_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->sent()->create(['project_id' => $project->id]);

        $response = $this->convert($this->authHeader($user), $project, $proposal);

        $response->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_NOT_APPROVED');
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_converting_a_changes_requested_proposal_fails_with_409_proposal_not_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->sent()->create([
            'project_id' => $project->id,
            'status' => 'changes_requested',
        ]);

        $response = $this->convert($this->authHeader($user), $project, $proposal);

        $response->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_NOT_APPROVED');
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_converting_an_approved_proposal_succeeds(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        $response = $this->convert($this->authHeader($user), $project, $proposal);

        $response->assertStatus(201);
        $this->assertDatabaseCount('contracts', 1);

        $contract = Contract::first();
        $this->assertSame($project->id, $contract->project_id);
        $this->assertSame($proposal->id, $contract->proposal_version_id);
        $this->assertSame('active', $contract->status);
        $this->assertNotNull($contract->signed_at);
        $this->assertSame($response->json('data.id'), $contract->id);
    }

    public function test_converting_the_same_proposal_twice_fails_with_409_contract_already_exists_and_correct_contract_id(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $headers = $this->authHeader($user);

        $first = $this->convert($headers, $project, $proposal);
        $first->assertStatus(201);
        $realContractId = $first->json('data.id');

        $second = $this->convert($headers, $project, $proposal);

        $second->assertStatus(409)->assertJsonPath('error.code', 'CONTRACT_ALREADY_EXISTS');
        $this->assertSame($realContractId, $second->json('error.details.contract_id'));

        // The second, rejected attempt did not create a duplicate row.
        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_converting_a_proposal_that_belongs_to_a_different_project_returns_404(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        // Approved proposal belongs to project B, but we try to convert it under project A's URL.
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $projectB->id]);

        $response = $this->convert($this->authHeader($user), $projectA, $proposal);

        $response->assertStatus(404);
        $this->assertDatabaseCount('contracts', 0);
    }
}
