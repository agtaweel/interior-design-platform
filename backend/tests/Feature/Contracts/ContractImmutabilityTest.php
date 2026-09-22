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
 * PROJECT_CONTEXT.md Sprint 5 immutability boundary: contract_value/proposal_version_id are
 * permanently locked at creation; start_date/end_date/terms_json are freely editable via PATCH.
 * UpdateContractRequest rejects (422 `prohibited`) rather than silently strips a payload that
 * includes either locked field — this class pins both the "editable fields work" half and the
 * "locked fields are rejected, and rejected WHOLESALE (not partially applied)" half, per the
 * task's explicit instruction not to assume atomicity, but to prove it: a single PATCH request
 * that mixes an allowed field with a prohibited one must apply NEITHER, since Laravel's
 * FormRequest validation fails (and the controller never runs) before `$model->update()` is
 * ever called for that request.
 */
class ContractImmutabilityTest extends TestCase
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

    private function contractIn(Project $project): Contract
    {
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        return Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '150000.00',
            'start_date' => null,
            'end_date' => null,
        ]);
    }

    public function test_patch_with_start_date_end_date_and_terms_json_succeeds_and_persists(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = $this->contractIn($project);

        $payload = [
            'start_date' => '2026-10-01',
            'end_date' => '2027-04-01',
            'terms_json' => ['terms' => 'Updated terms text', 'warranty_period' => '12 months'],
        ];

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/contracts/{$contract->id}", $payload);

        $response->assertStatus(200);
        // Dates are serialized as full ISO8601 timestamps by the default Carbon->JsonResource
        // pipeline (not bare "Y-m-d" strings) — compare via Carbon::parse()->toDateString()
        // rather than assuming a specific wire format.
        $this->assertSame('2026-10-01', \Illuminate\Support\Carbon::parse($response->json('data.start_date'))->toDateString());
        $this->assertSame('2027-04-01', \Illuminate\Support\Carbon::parse($response->json('data.end_date'))->toDateString());
        $response->assertJsonPath('data.terms_json.terms', 'Updated terms text');

        $fresh = $contract->fresh();
        $this->assertSame('2026-10-01', $fresh->start_date->toDateString());
        $this->assertSame('2027-04-01', $fresh->end_date->toDateString());
        $this->assertSame('Updated terms text', $fresh->terms_json['terms']);
        $this->assertSame('12 months', $fresh->terms_json['warranty_period']);
    }

    public function test_patch_including_contract_value_is_rejected_with_422_and_applies_nothing_from_that_request(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = $this->contractIn($project);
        $originalValue = (string) $contract->contract_value;

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                // A co-present, otherwise-valid field bundled in the SAME request — must NOT
                // be applied either, proving the whole request is atomic (rejected wholesale),
                // not "apply what's valid, ignore what's not".
                'start_date' => '2026-11-01',
                'contract_value' => '999999.99',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');

        $fresh = $contract->fresh();
        $this->assertSame($originalValue, (string) $fresh->contract_value);
        // The co-present start_date change from the SAME rejected request was not applied.
        $this->assertNull($fresh->start_date);

        // A follow-up GET confirms the same via the read path, not just a fresh() re-read.
        $get = $this->withHeaders($this->authHeader($user))->getJson("/api/v1/contracts/{$contract->id}");
        $get->assertStatus(200);
        $this->assertSame($originalValue, (string) $get->json('data.contract_value'));
        $this->assertNull($get->json('data.start_date'));
    }

    public function test_patch_including_proposal_version_id_is_rejected_with_422_and_applies_nothing_from_that_request(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = $this->contractIn($project);
        $originalProposalVersionId = $contract->proposal_version_id;
        $someOtherProposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id, 'version_no' => 2]);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                'end_date' => '2027-01-15',
                'proposal_version_id' => $someOtherProposal->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');

        $fresh = $contract->fresh();
        $this->assertSame($originalProposalVersionId, $fresh->proposal_version_id);
        // The co-present end_date change from the SAME rejected request was not applied either.
        $this->assertNull($fresh->end_date);

        $get = $this->withHeaders($this->authHeader($user))->getJson("/api/v1/contracts/{$contract->id}");
        $get->assertStatus(200);
        $this->assertSame($originalProposalVersionId, $get->json('data.proposal_version.id'));
        $this->assertNull($get->json('data.end_date'));
    }
}
