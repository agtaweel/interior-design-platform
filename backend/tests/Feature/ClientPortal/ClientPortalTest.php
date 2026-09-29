<?php

namespace Tests\Feature\ClientPortal;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRD v3 §17 "Client Portal" (ClientPortalLinkController, PublicClientPortalController). Covers
 * link issuance/revocation, every read-only page, and — most importantly — that no
 * supplier-cost/markup/profit/internal-linkage field ever reaches this token-authenticated
 * public surface.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_PROJECTS => true,
            Permissions::MANAGE_BOQ => true,
            Permissions::MANAGE_PROCUREMENT => true,
        ]);
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    private function issueToken(User $user, Project $project): string
    {
        return $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/client-portal-link")
            ->assertStatus(201)
            ->json('data.token');
    }

    public function test_issuing_a_link_requires_manage_projects_permission(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/client-portal-link")
            ->assertStatus(403);
    }

    public function test_overview_returns_client_safe_project_and_financial_summary(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $token = $this->issueToken($user, $project);

        $response = $this->getJson("/api/v1/public/client-portal/{$token}");

        $response->assertStatus(200);
        $this->assertSame($project->code, $response->json('data.project.code'));
        $this->assertArrayHasKey('value', $response->json('data.financials'));
        $this->assertArrayHasKey('collected', $response->json('data.financials'));
        $this->assertArrayHasKey('outstanding', $response->json('data.financials'));
    }

    public function test_an_invalid_token_returns_a_generic_404(): void
    {
        $this->getJson('/api/v1/public/client-portal/not-a-real-token')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'client_portal_link_invalid');
    }

    public function test_a_revoked_token_can_no_longer_access_the_portal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $token = $this->issueToken($user, $project);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/client-portal-link/revoke", ['token' => $token])
            ->assertStatus(204);

        $this->getJson("/api/v1/public/client-portal/{$token}")->assertStatus(404);
    }

    public function test_change_orders_endpoint_never_leaks_boq_item_id_or_internal_linkage(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => BoqCategory::factory()->create(['project_id' => $project->id])->id,
        ]);
        $changeOrder = ChangeOrder::factory()->create(['project_id' => $project->id, 'status' => 'approved', 'sent_at' => now(), 'approved_at' => now()]);
        ChangeOrderItem::factory()->create([
            'change_order_id' => $changeOrder->id,
            'action' => 'modify',
            'boq_item_id' => $boqItem->id,
            'old_unit_price' => '100.00',
            'new_unit_price' => '150.00',
            'line_delta' => '50.00',
        ]);
        $token = $this->issueToken($user, $project);

        $response = $this->getJson("/api/v1/public/client-portal/{$token}/change-orders");

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringNotContainsString('boq_item_id', $body);
        $this->assertStringNotContainsString('"id"', $body, 'change order items must not expose any internal id field');
    }

    public function test_proposals_and_payments_and_contract_pages_never_leak_cost_or_margin_fields(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'material_unit_cost' => '999.00',
            'labor_unit_cost' => '888.00',
            'other_unit_cost' => '777.00',
        ]);

        // Real send flow (not the factory's minimal sent() state) so ProposalPresenter's full
        // payload — and therefore this leak check — is meaningful.
        $proposalId = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/proposals/{$proposalId}/send")
            ->assertStatus(200);
        $approvedVersion = ProposalVersion::query()->findOrFail($proposalId);
        $approvedVersion->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposalId}")
            ->assertStatus(201);
        $contract = Contract::query()->where('project_id', $project->id)->firstOrFail();
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'amount' => '1000.00',
            'percentage' => null,
            'status' => 'pending',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '1000.00',
                'payment_method' => 'cash',
                'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);

        $token = $this->issueToken($user, $project);
        $forbidden = ['material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'source_boq_item_id', 'supplier_cost', 'markup_percentage', 'markup_amount', 'supervision_percentage', 'supervision_amount', 'quoted_cost', 'committed_cost', 'actual_cost', 'gross_profit', 'margin_percent'];

        foreach ([
            "/api/v1/public/client-portal/{$token}/proposals",
            "/api/v1/public/client-portal/{$token}/proposals/{$proposalId}",
            "/api/v1/public/client-portal/{$token}/contract",
            "/api/v1/public/client-portal/{$token}/payments",
            "/api/v1/public/client-portal/{$token}",
        ] as $url) {
            $body = $this->getJson($url)->assertStatus(200)->getContent();

            foreach ($forbidden as $field) {
                $this->assertStringNotContainsString($field, $body, "{$url} leaked '{$field}'");
            }
        }

        // The cost values themselves must never appear either, not just the field names.
        $costBody = $this->getJson("/api/v1/public/client-portal/{$token}/proposals/{$proposalId}")->getContent();
        foreach (['999.00', '888.00', '777.00'] as $costValue) {
            $this->assertStringNotContainsString($costValue, $costBody, "Client portal proposal leaked cost value '{$costValue}'");
        }
    }

    public function test_a_proposal_belonging_to_a_different_project_cannot_be_fetched_through_this_projects_token(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $proposalIdB = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$projectB->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/proposals/{$proposalIdB}/send")
            ->assertStatus(200);

        $tokenA = $this->issueToken($user, $projectA);

        $this->getJson("/api/v1/public/client-portal/{$tokenA}/proposals/{$proposalIdB}")
            ->assertStatus(404);
    }

    public function test_media_gallery_lists_and_streams_project_files(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $file = UploadedFile::fake()->createWithContent('design.png', base64_decode(self::PNG_BASE64));
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/media", ['file' => $file, 'collection' => 'designs'])
            ->assertStatus(201);

        $token = $this->issueToken($user, $project);

        $list = $this->getJson("/api/v1/public/client-portal/{$token}/media")->assertStatus(200);
        $mediaId = $list->json('data.0.id');
        $this->assertNotNull($mediaId);

        $this->getJson("/api/v1/public/client-portal/{$token}/media/{$mediaId}/file")->assertStatus(200);
    }

    public function test_a_member_of_another_organization_cannot_issue_a_link_for_this_project(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $projectA = $this->projectIn($organizationA);
        $userB = $this->fullAccessUser($organizationB);

        $this->withHeaders($this->authHeader($userB))
            ->postJson("/api/v1/projects/{$projectA->id}/client-portal-link")
            ->assertStatus(404);
    }
}
