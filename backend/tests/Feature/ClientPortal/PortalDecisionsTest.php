<?php

namespace Tests\Feature\ClientPortal;

use App\Models\Approval;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\SignedLink;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/SIMPLIFICATION_REVIEW.md P3.1 — the client portal link is the one link a client gets:
 * sending reuses it, staff can copy it again, and the client approves / requests changes /
 * rejects inside the portal with the code issued for that document.
 */
class PortalDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private function staffIn(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [
            Permissions::MANAGE_BOQ => true,
            Permissions::MANAGE_PROJECTS => true,
        ]]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    private function asStaff(): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->staff->createToken('t')->plainTextToken]);
    }

    /** The client is anonymous — drop any staff auth left over from earlier requests. */
    private function asClient(): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this;
    }

    /** @return array{0: int, 1: string, 2: string} [proposalId, portalToken, otp] */
    private function sendProposal(Project $project): array
    {
        $id = $this->asStaff()->postJson("/api/v1/projects/{$project->id}/proposals", [])->assertStatus(201)->json('data.id');
        $send = $this->asStaff()->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $portalUrl = $send->json('data.portal_url');
        $this->assertStringEndsWith("/proposals/{$id}", $portalUrl);
        preg_match('#/p/client-portal/([^/]+)/#', $portalUrl, $m);

        return [$id, $m[1], $send->json('data.otp_code')];
    }

    /** @return array{0: string, 1: string, 2: string} [number, portalToken, otp] */
    private function sendChangeOrder(Project $project): array
    {
        $id = $this->asStaff()->postJson("/api/v1/projects/{$project->id}/change-orders", [
            'reason' => 'Extra vanity.',
            'items' => [['action' => 'add', 'description' => 'Vanity', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 500]],
        ])->assertStatus(201)->json('data.id');
        $send = $this->asStaff()->postJson("/api/v1/change-orders/{$id}/send")->assertStatus(200);
        preg_match('#/p/client-portal/([^/]+)/change-orders$#', $send->json('data.portal_url'), $m);

        return [ChangeOrder::find($id)->number, $m[1], $send->json('data.otp_code')];
    }

    public function test_every_send_reuses_one_portal_link_that_staff_can_copy_again(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);

        [, $first] = $this->sendProposal($project);
        [, $second] = $this->sendProposal($project);
        [, $third] = $this->sendChangeOrder($project);

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->asStaff()->getJson("/api/v1/projects/{$project->id}/client-portal-link")
            ->assertStatus(200)
            ->assertJsonPath('data.token', $first);

        // Stored encrypted, never as plain text, and never serialised.
        $link = SignedLink::query()->where('purpose', 'client_portal')->first();
        $this->assertNotSame($first, $link->getRawOriginal('token_encrypted'));
        $this->assertArrayNotHasKey('token_encrypted', $link->toArray());
    }

    public function test_client_approves_a_proposal_inside_the_portal_with_its_code(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);
        [$id, $portal, $otp] = $this->sendProposal($project);

        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/proposals/{$id}/approve", ['name' => 'Mona', 'otp' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');

        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/proposals/{$id}/approve", ['name' => 'Mona', 'otp' => $otp], ['Idempotency-Key' => 'k1'])
            ->assertStatus(200)->assertJsonPath('status', 'approved');
        // Replay returns the stored result, not a second approval.
        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/proposals/{$id}/approve", ['name' => 'Mona', 'otp' => $otp], ['Idempotency-Key' => 'k1'])
            ->assertStatus(200)->assertJsonPath('status', 'approved');

        $this->assertSame('approved', ProposalVersion::find($id)->status);
        $this->assertSame(1, Approval::where('entity_id', $id)->where('status', 'approved')->count());
        $this->assertSame('Mona', Approval::where('entity_id', $id)->value('comment'));
    }

    public function test_client_can_request_changes_inside_the_portal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);
        [$id, $portal] = $this->sendProposal($project);

        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/proposals/{$id}/request-changes", ['name' => 'Mona', 'comment' => 'Cheaper tiles please'])
            ->assertStatus(200)->assertJsonPath('data.status', 'changes_requested');

        // Once sent back, it no longer awaits a decision in the portal.
        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/proposals/{$id}/request-changes", ['name' => 'Mona', 'comment' => 'again'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_NOT_AWAITING_DECISION');
    }

    public function test_a_portal_link_cannot_act_on_another_projects_documents(): void
    {
        $organization = Organization::factory()->create();
        $this->staff = $this->staffIn($organization);
        [, $portalA] = $this->sendProposal($this->projectIn($organization));
        [$idB, , $otpB] = $this->sendProposal($projectB = $this->projectIn($organization));
        [$numberB] = $this->sendChangeOrder($projectB);

        $this->asClient()->postJson("/api/v1/public/client-portal/{$portalA}/proposals/{$idB}/approve", ['name' => 'X', 'otp' => $otpB])
            ->assertStatus(404);
        $this->asClient()->postJson("/api/v1/public/client-portal/{$portalA}/change-orders/{$numberB}/reject", ['name' => 'X', 'comment' => 'no'])
            ->assertStatus(404);
        $this->assertSame('sent', ProposalVersion::find($idB)->status);
    }

    public function test_client_approves_and_rejects_change_orders_inside_the_portal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);
        $proposal = ProposalVersion::factory()->create(['project_id' => $project->id, 'status' => 'approved']);
        Contract::factory()->create(['project_id' => $project->id, 'proposal_version_id' => $proposal->id]);

        [$approveMe, $portal, $otp] = $this->sendChangeOrder($project);
        [$rejectMe] = $this->sendChangeOrder($project);

        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/change-orders/{$approveMe}/approve", ['name' => 'Mona', 'otp' => $otp])
            ->assertStatus(200)->assertJsonPath('status', 'approved')->assertJsonPath('applied', false);
        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/change-orders/{$rejectMe}/reject", ['name' => 'Mona', 'comment' => 'Not now'])
            ->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/change-orders/{$rejectMe}/reject", ['name' => 'Mona', 'comment' => 'again'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_AWAITING_DECISION');

        $this->assertSame('approved', ChangeOrder::where('number', $approveMe)->value('status'));
        $this->assertSame('rejected', ChangeOrder::where('number', $rejectMe)->value('status'));
    }

    public function test_an_applied_change_order_cannot_be_approved_again(): void
    {
        $organization = Organization::factory()->create(['auto_apply_change_orders' => true]);
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);
        $proposal = ProposalVersion::factory()->create(['project_id' => $project->id, 'status' => 'approved']);
        Contract::factory()->create(['project_id' => $project->id, 'proposal_version_id' => $proposal->id, 'contract_value' => '1000.00']);
        [$number, $portal, $otp] = $this->sendChangeOrder($project);

        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/change-orders/{$number}/approve", ['name' => 'Mona', 'otp' => $otp])
            ->assertStatus(200)->assertJsonPath('applied', true);
        // A double-tap without an idempotency key must not flip 'applied' back to 'approved'.
        $this->asClient()->postJson("/api/v1/public/client-portal/{$portal}/change-orders/{$number}/approve", ['name' => 'Mona', 'otp' => $otp])
            ->assertStatus(409);

        $this->assertSame('applied', ChangeOrder::where('number', $number)->value('status'));
        $this->assertSame('1500.00', Contract::where('project_id', $project->id)->value('contract_value'));
    }

    public function test_revoking_without_a_token_closes_the_portal_and_the_next_send_issues_a_new_one(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);
        [, $old] = $this->sendProposal($project);

        $this->asStaff()->postJson("/api/v1/projects/{$project->id}/client-portal-link/revoke")->assertStatus(204);
        $this->asStaff()->getJson("/api/v1/projects/{$project->id}/client-portal-link")->assertStatus(200)->assertJsonPath('data', null);
        $this->asClient()->getJson("/api/v1/public/client-portal/{$old}")->assertStatus(404);

        [, $new] = $this->sendProposal($project);
        $this->assertNotSame($old, $new);
        $this->asClient()->getJson("/api/v1/public/client-portal/{$new}")->assertStatus(200);
    }

    public function test_a_portal_link_issued_before_this_change_is_replaced_by_a_retrievable_one(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->staff = $this->staffIn($organization);
        // Old-style, hash-only portal link (can never be shown again).
        $legacy = app(SignedLinkService::class)->issue('client_portal', ['project_id' => $project->id], now()->addYear());

        $this->asStaff()->getJson("/api/v1/projects/{$project->id}/client-portal-link")->assertJsonPath('data', null);
        [, $portal] = $this->sendProposal($project);

        $this->assertNotSame($legacy, $portal);
        $this->asClient()->getJson("/api/v1/public/client-portal/{$legacy}")->assertStatus(200); // still works until revoked
        $this->asClient()->getJson("/api/v1/public/client-portal/{$portal}")->assertStatus(200);
    }
}
