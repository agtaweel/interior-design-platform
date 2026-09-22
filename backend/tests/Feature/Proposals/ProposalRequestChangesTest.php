<?php

namespace Tests\Feature\Proposals;

use App\Models\Approval;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
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
 * PROJECT_CONTEXT.md Sprint 4 -> POST /public/proposals/{token}/request-changes: lower-stakes
 * than approve() (a comment, not a binding commercial action) — no OTP required. Sets
 * proposal_versions.status = 'changes_requested' and creates an approvals row with that status.
 */
class ProposalRequestChangesTest extends TestCase
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

    private function seedBoqItem(Project $project): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
    }

    private function createAndSendProposal(array $headers, Project $project): array
    {
        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        return [$id, $token];
    }

    public function test_request_changes_requires_no_otp_field_at_all(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$id, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        // No 'otp' key anywhere in the body — must succeed.
        $response = $this->postJson("/api/v1/public/proposals/{$token}/request-changes", [
            'name' => 'Jane Client',
            'comment' => 'Please swap the flooring to porcelain.',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'changes_requested');
        $this->assertSame('changes_requested', ProposalVersion::find($id)->status);
    }

    public function test_request_changes_creates_an_approvals_row_with_status_changes_requested(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$id, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/request-changes", [
            'name' => 'Jane Client',
            'comment' => 'Needs revision.',
        ])->assertStatus(200);

        $approval = Approval::first();
        $this->assertNotNull($approval);
        $this->assertSame(ProposalVersion::ENTITY_TYPE, $approval->entity_type);
        $this->assertSame($id, $approval->entity_id);
        $this->assertSame('client', $approval->approver_type);
        $this->assertNull($approval->user_id);
        $this->assertSame('changes_requested', $approval->status);
        $this->assertStringContainsString('Jane Client', $approval->comment);
        $this->assertStringContainsString('Needs revision.', $approval->comment);
        $this->assertNotNull($approval->ip_address);
    }

    public function test_comment_is_required_for_request_changes(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/request-changes", ['name' => 'Jane Client'])
            ->assertStatus(422);
    }

    public function test_request_changes_on_an_already_approved_version_returns_409(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$id, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        ProposalVersion::find($id)->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

        $response = $this->postJson("/api/v1/public/proposals/{$token}/request-changes", [
            'name' => 'Jane Client',
            'comment' => 'Too late, changed my mind.',
        ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_ALREADY_APPROVED');
        $this->assertSame('approved', ProposalVersion::find($id)->status);
    }

    public function test_request_changes_does_not_touch_otp_challenge_state(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/request-changes", [
            'name' => 'Jane Client',
            'comment' => 'Revise please.',
        ])->assertStatus(200);

        $this->assertNull(\App\Models\OtpChallenge::first()->verified_at);
        $this->assertSame(0, \App\Models\OtpChallenge::first()->attempts);
    }
}
