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
 * Sprint 5's Definition of Done claim ("An approved proposal can become a contract without
 * losing the approved commercial snapshot") has a second, easy-to-miss half beyond value
 * integrity: a contract existing must not unlock proposal edits, and editing the contract's own
 * terms after conversion must not entangle it back with the source proposal's content_json.
 * Sprint 4's immutability rule (editable only while status == 'draft') and Sprint 5's "own
 * independent copy" design for terms_json (ContractService::seedTermsJson()'s docblock) are two
 * separate mechanisms; this test proves they actually compose correctly once a real conversion
 * has happened, rather than trusting each sprint's own unit-level tests in isolation.
 */
class ContractProposalIndependenceTest extends TestCase
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

    public function test_a_contract_existing_does_not_unlock_editing_the_source_approved_proposal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'content_json' => ['terms' => 'Original terms'],
        ]);

        // Confirm the proposal was already locked BEFORE conversion (Sprint 4 baseline).
        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$proposal->id}", ['content_json' => ['terms' => 'Sneaky pre-contract edit']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPOSAL_NOT_EDITABLE');

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);

        // Still locked AFTER conversion — a contract existing must not change the proposal's
        // own editability rule in either direction.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$proposal->id}", ['content_json' => ['terms' => 'Sneaky post-contract edit']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPOSAL_NOT_EDITABLE');

        $this->assertSame('Original terms', $proposal->fresh()->content_json['terms']);
    }

    public function test_editing_the_contracts_terms_after_conversion_does_not_mutate_the_source_proposals_content_json(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'content_json' => ['terms' => 'Original terms', 'payment_plan' => 'Original plan'],
        ]);

        $created = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        $contractId = $created->json('data.id');

        // Edit the CONTRACT's own terms_json after conversion.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/contracts/{$contractId}", [
                'terms_json' => ['terms' => 'Amended contract terms', 'payment_plan' => 'Amended plan'],
            ])
            ->assertStatus(200);

        // The source proposal's content_json (and the proposal itself) must be completely
        // untouched — terms_json became the contract's own independent copy at creation time.
        $freshProposal = $proposal->fresh();
        $this->assertSame('Original terms', $freshProposal->content_json['terms']);
        $this->assertSame('Original plan', $freshProposal->content_json['payment_plan']);
        $this->assertSame('approved', $freshProposal->status);

        $freshContract = Contract::find($contractId);
        $this->assertSame('Amended contract terms', $freshContract->terms_json['terms']);
    }
}
