<?php

namespace Tests\Feature\Proposals;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 4 "Immutability rule" (critical NFR): a proposal_version is
 * editable ONLY while status == 'draft'. The boundary is at 'sent', NOT 'approved' — a
 * "sent" (not yet approved/changes_requested) version must already reject PATCH with 409
 * PROPOSAL_NOT_EDITABLE. Also covers POST /proposals/{id}/send returning 409 PROPOSAL_NOT_DRAFT
 * once a version has left 'draft'.
 */
class ProposalImmutabilityTest extends TestCase
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

    private function createDraft(array $headers, Project $project): int
    {
        return $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');
    }

    public function test_patch_succeeds_while_status_is_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        $id = $this->createDraft($headers, $project);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$id}", ['content_json' => ['cover_note' => 'Updated note']])
            ->assertStatus(200)
            ->assertJsonPath('data.content_json.cover_note', 'Updated note');
    }

    public function test_patch_fails_with_409_proposal_not_editable_once_sent(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        $id = $this->createDraft($headers, $project);
        $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        // The version is 'sent' now, NOT yet 'approved' — this is the exact boundary
        // PROJECT_CONTEXT.md emphasizes: immutability starts at 'sent'.
        $this->assertSame('sent', \App\Models\ProposalVersion::find($id)->status);

        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$id}", ['content_json' => ['cover_note' => 'Should not apply']]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_NOT_EDITABLE');

        // Content genuinely unchanged in the DB, not just a rejected-but-partially-applied write.
        $this->assertNotSame(
            'Should not apply',
            \App\Models\ProposalVersion::find($id)->content_json['cover_note'] ?? null
        );
    }

    public function test_patch_fails_once_approved_too(): void
    {
        // Approval only further locks an already-locked (sent) version — it does not introduce
        // a second, distinct immutability boundary. Confirms the 409 persists past approval.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        $id = $this->createDraft($headers, $project);
        $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        \App\Models\ProposalVersion::find($id)->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$id}", ['content_json' => ['cover_note' => 'x']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPOSAL_NOT_EDITABLE');
    }

    public function test_sending_an_already_sent_version_returns_409_proposal_not_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        $id = $this->createDraft($headers, $project);
        $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $response = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send");

        $response->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_NOT_DRAFT');

        // Only one signed link / otp challenge should exist — a second send() call must not
        // have silently re-issued a new one.
        $this->assertDatabaseCount('signed_links', 1);
        $this->assertDatabaseCount('otp_challenges', 1);
    }

    public function test_sending_an_approved_version_also_returns_409_proposal_not_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        $id = $this->createDraft($headers, $project);
        $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);
        \App\Models\ProposalVersion::find($id)->forceFill(['status' => 'approved'])->save();

        $this->withHeaders($headers)
            ->postJson("/api/v1/proposals/{$id}/send")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPOSAL_NOT_DRAFT');
    }

    public function test_resnapshot_flag_is_ignored_reads_do_not_bypass_the_draft_guard(): void
    {
        // The controller checks status === 'draft' BEFORE ever looking at the resnapshot flag,
        // so a sent version can't be re-snapshotted by smuggling resnapshot=true through PATCH.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        $id = $this->createDraft($headers, $project);
        $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/proposals/{$id}", ['resnapshot' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPOSAL_NOT_EDITABLE');
    }
}
