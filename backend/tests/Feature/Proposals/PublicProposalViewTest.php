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
 * PROJECT_CONTEXT.md Sprint 4 -> GET /public/proposals/{token}: the S11 client-portal payload.
 * Must NEVER include material_unit_cost/labor_unit_cost/other_unit_cost/source_boq_item_id or
 * the subtotal/markup_total/fees_total/discount_total breakdown — only grand_total from the
 * pricing block. Also covers the invalid/nonexistent-token path (must be a clean 404, no leak).
 */
class PublicProposalViewTest extends TestCase
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

    private function seedBoqItem(Project $project, array $overrides = []): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(array_merge([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'material_unit_cost' => 999,
            'labor_unit_cost' => 888,
            'other_unit_cost' => 777,
        ], $overrides));
    }

    /**
     * Creates + sends a proposal via the real HTTP endpoints and returns
     * [proposalId, token, otpCode].
     */
    private function createAndSendProposal(array $headers, Project $project): array
    {
        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        return [$id, $token, $send->json('data.otp_code')];
    }

    public function test_public_view_returns_client_shaped_payload_with_zero_cost_fields(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $item = $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        [, $token] = $this->createAndSendProposal($headers, $project);

        $response = $this->getJson("/api/v1/public/proposals/{$token}")->assertStatus(200);
        $body = $response->getContent();

        // No cost/margin fields anywhere in the raw response body, not just absent from a
        // particular sub-array — this is a blanket string-level check against leak-by-typo.
        foreach (['material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'source_boq_item_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "Public proposal view leaked '{$forbidden}'");
        }
        foreach (['999', '888', '777'] as $costValue) {
            $this->assertStringNotContainsString($costValue, $body, 'Public proposal view leaked a raw cost figure');
        }

        // pricing block contains ONLY grand_total.
        $pricing = $response->json('data.pricing');
        $this->assertSame(['grand_total'], array_keys($pricing));
        $this->assertArrayNotHasKey('subtotal', $pricing);
        $this->assertArrayNotHasKey('markup_total', $pricing);
        $this->assertArrayNotHasKey('fees_total', $pricing);
        $this->assertArrayNotHasKey('discount_total', $pricing);

        // Items are client-shaped only.
        $item0 = $response->json('data.items.0');
        $this->assertEqualsCanonicalizing(
            ['description', 'quantity', 'unit', 'unit_price', 'line_total'],
            array_keys($item0)
        );
    }

    public function test_public_view_of_a_nonexistent_token_is_a_clean_404_with_no_state_leak(): void
    {
        $response = $this->getJson('/api/v1/public/proposals/this-token-does-not-exist-at-all');

        $response->assertStatus(404);
        $this->assertArrayHasKey('error', $response->json());
        $this->assertSame('proposal_link_invalid', $response->json('error.code'));
        // No trace of internal ids/stack traces/db-driver messages in the body.
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('Exception', $response->getContent());
    }

    public function test_public_view_of_an_expired_or_revoked_link_is_also_a_clean_404(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        [, $token] = $this->createAndSendProposal($headers, $project);

        \App\Models\SignedLink::query()->update(['expires_at' => now()->subDay()]);

        $this->getJson("/api/v1/public/proposals/{$token}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'proposal_link_invalid');
    }

    public function test_public_view_reflects_live_status_overlay_after_approval(): void
    {
        // ProposalPresenter overlays LIVE status/approved_at on top of the frozen snapshot_json
        // — confirms a sent-then-approved version doesn't keep showing status=sent forever.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);

        [$id, $token] = $this->createAndSendProposal($headers, $project);

        \App\Models\ProposalVersion::find($id)->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

        $response = $this->getJson("/api/v1/public/proposals/{$token}")->assertStatus(200);
        $response->assertJsonPath('data.proposal.status', 'approved');
        $this->assertNotNull($response->json('data.proposal.approved_at'));
    }
}
