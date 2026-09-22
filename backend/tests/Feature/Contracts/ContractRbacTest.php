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
 * RBAC coverage for Sprint 5's contracts surface, per ContractController's docblock: reads
 * (index/show/pdf) require only an active membership; the one mutating action this controller
 * has beyond conversion (update) plus from-proposal conversion itself require
 * Permissions::MANAGE_BOQ — reused from Sprint 4's proposals rather than a new
 * `manage_contracts` permission, per PROJECT_CONTEXT.md's explicit instruction to keep the
 * commercial workflow's permission story consistent. Same posture ProposalRbacTest already
 * established for Sprint 4.
 */
class ContractRbacTest extends TestCase
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

    public function test_read_only_member_can_list_view_and_download_pdf_but_not_convert_or_update(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        // Seed the existing contract directly via the model factory rather than through an
        // authenticated HTTP call as a second ("creator") user — same deliberate choice
        // ProposalRbacTest documents (this codebase's Sanctum-token test harness has a
        // reproducible cross-user-same-request quirk for two different users hitting a
        // mutating route back-to-back within one test method). Only ONE user authenticates
        // over real HTTP in this test.
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            // Pinned to null (rather than the factory's fake()->optional() random dates) so the
            // "PATCH was rejected and applied nothing" assertion below has a known starting value.
            'start_date' => null,
        ]);

        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        // --- Reads succeed ---
        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/contracts")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        // --- Writes are forbidden ---
        $anotherApprovedProposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id, 'version_no' => 2]);
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$anotherApprovedProposal->id}")
            ->assertStatus(403);
        $this->assertDatabaseCount('contracts', 1); // only the pre-seeded one

        $this->withHeaders($headers)
            ->patchJson("/api/v1/contracts/{$contract->id}", ['start_date' => '2026-10-01'])
            ->assertStatus(403);
        $this->assertNull($contract->fresh()->start_date);
    }

    public function test_member_with_manage_boq_can_convert_and_update_a_contract(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $create = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        $id = $create->json('data.id');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/contracts/{$id}", ['start_date' => '2026-10-01'])
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_to_internal_contract_routes_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        $this->getJson("/api/v1/projects/{$project->id}/contracts")->assertStatus(401);
        $this->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")->assertStatus(401);
    }
}
