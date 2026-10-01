<?php

namespace Tests\Feature\Marketplace;

use App\Models\Approval;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\ClientUser;
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
 * BRD v4 "Client Marketplace" Phase C — GET/POST /client/deals(/{proposal}...). "Deal" is the
 * existing ProposalVersion system reused as-is (see ProposalApprovalService's docblock); the
 * headline behavior under test is the user-confirmed decision to SKIP OTP entirely for this
 * logged-in flow, plus the ownership boundary (ClientOwnershipResolver) that replaces tenant
 * scoping on these token-free client routes.
 */
class ClientDealTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithManageBoq(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [Permissions::MANAGE_BOQ => true]]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * Auth::forgetGuards() before building each header — see the
     * laravel-sanctum-test-user-switch-quirk memory: without this, a later request in the same
     * test method can resolve $request->user() as a PREVIOUSLY authenticated actor even with a
     * fresh, correct Bearer token, producing confusing 403/404s that look like real bugs.
     */
    private function staffAuthHeader(User $user): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    private function clientAuthHeader(ClientUser $clientUser): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$clientUser->createToken('t')->plainTextToken];
    }

    /**
     * Builds a project owned (via Client::client_user_id) by $clientUser, with one sent
     * ProposalVersion ready to approve — mirrors ProposalApprovalTest's own setup, since
     * marketplace deals are the exact same ProposalVersion machinery.
     */
    private function projectWithSentDealFor(ClientUser $clientUser): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'client_user_id' => $clientUser->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $staff = $this->staffWithManageBoq($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);

        $id = $this->withHeaders($this->staffAuthHeader($staff))
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->staffAuthHeader($staff))
            ->postJson("/api/v1/proposals/{$id}/send")
            ->assertStatus(200);

        return [$project, $id];
    }

    public function test_client_can_list_their_own_deals_only(): void
    {
        $clientUser = ClientUser::factory()->create();
        [, $ownDealId] = $this->projectWithSentDealFor($clientUser);

        $otherClient = ClientUser::factory()->create();
        $this->projectWithSentDealFor($otherClient);

        $response = $this->withHeaders($this->clientAuthHeader($clientUser))->getJson('/api/v1/client/deals');

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownDealId);
    }

    public function test_client_can_view_their_own_deal_detail(): void
    {
        $clientUser = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($clientUser);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->getJson("/api/v1/client/deals/{$dealId}")
            ->assertStatus(200)
            ->assertJsonMissingPath('data.pricing.subtotal')
            ->assertJsonPath('data.proposal.status', 'sent');
    }

    public function test_client_cannot_view_another_clients_deal(): void
    {
        $clientUser = ClientUser::factory()->create();
        $otherClient = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($otherClient);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->getJson("/api/v1/client/deals/{$dealId}")
            ->assertStatus(404);
    }

    public function test_client_can_approve_their_own_deal_without_any_otp(): void
    {
        // Built directly via Eloquent, not through a staff-authenticated HTTP round trip like
        // projectWithSentDealFor() — see laravel-sanctum-test-user-switch-quirk memory: a prior
        // staff HTTP call earlier in the SAME test method can leave auth() resolving as that
        // staff user even after Auth::forgetGuards() + a fresh client Bearer token, which would
        // make the actor_user_id assertion below false-fail. Sidestepping the HTTP layer for
        // setup (rather than fighting the quirk) isolates what's actually under test: the
        // approve() call itself.
        $clientUser = ClientUser::factory()->create(['name' => 'Laila Farouk']);
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'client_user_id' => $clientUser->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $dealId = ProposalVersion::factory()->sent()->create(['project_id' => $project->id])->id;

        $response = $this->withHeaders($this->clientAuthHeader($clientUser))
            ->postJson("/api/v1/client/deals/{$dealId}/approve", ['comment' => 'Looks great!']);

        $response->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ProposalVersion::find($dealId)->status);

        $approval = Approval::query()->where('entity_id', $dealId)->where('entity_type', ProposalVersion::ENTITY_TYPE)->first();
        $this->assertNotNull($approval);
        $this->assertSame('client', $approval->approver_type);
        $this->assertNull($approval->user_id);
        $this->assertStringContainsString('Laila Farouk', $approval->comment);

        // Regression: audit_logs.actor_user_id has a foreign key to `users` only — a
        // ClientUser approving this proposal (which fires ProposalVersion's Auditable trait on
        // the status update) must never try to store a client_users id there. Confirmed live
        // against Postgres that this previously raised a foreign key violation (23503); sqlite,
        // which this suite runs on, doesn't enforce the FK by default so the exception itself
        // wouldn't reproduce here — asserting the stored value directly is what actually pins
        // the fix (see Auditable::recordAudit()'s docblock).
        $auditLog = \App\Models\AuditLog::query()
            ->where('entity_type', 'ProposalVersion')
            ->where('entity_id', $dealId)
            ->where('action', 'updated')
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertNull($auditLog->actor_user_id);
    }

    public function test_approving_an_already_approved_deal_returns_409(): void
    {
        $clientUser = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($clientUser);
        $header = $this->clientAuthHeader($clientUser);

        $this->withHeaders($header)->postJson("/api/v1/client/deals/{$dealId}/approve")->assertStatus(200);

        $this->withHeaders($header)
            ->postJson("/api/v1/client/deals/{$dealId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROPOSAL_ALREADY_APPROVED');
    }

    public function test_client_cannot_approve_another_clients_deal(): void
    {
        $clientUser = ClientUser::factory()->create();
        $otherClient = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($otherClient);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->postJson("/api/v1/client/deals/{$dealId}/approve")
            ->assertStatus(404);

        $this->assertSame('sent', ProposalVersion::find($dealId)->status);
    }

    public function test_request_changes_requires_a_comment(): void
    {
        $clientUser = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($clientUser);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->postJson("/api/v1/client/deals/{$dealId}/request-changes", [])
            ->assertStatus(422);
    }

    public function test_client_can_request_changes_on_their_own_deal(): void
    {
        $clientUser = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($clientUser);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->postJson("/api/v1/client/deals/{$dealId}/request-changes", ['comment' => 'Please swap the tile finish.'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'changes_requested');

        $this->assertSame('changes_requested', ProposalVersion::find($dealId)->status);
    }

    public function test_approval_is_disabled_when_the_otp_kill_switch_is_flipped_on(): void
    {
        config(['fitout.marketplace_deal_requires_otp' => true]);

        $clientUser = ClientUser::factory()->create();
        [, $dealId] = $this->projectWithSentDealFor($clientUser);

        $this->withHeaders($this->clientAuthHeader($clientUser))
            ->postJson("/api/v1/client/deals/{$dealId}/approve")
            ->assertStatus(501);

        $this->assertSame('sent', ProposalVersion::find($dealId)->status);
    }
}
