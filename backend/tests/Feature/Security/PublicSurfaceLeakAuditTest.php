<?php

namespace Tests\Feature\Security;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Client;
use App\Models\InvoiceDocument;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRD v3 §6 "never expose to a client" audit (V3-6). A single consolidated sweep across EVERY
 * token-authenticated public surface this app has (proposal approval page, change-order approval
 * page, and the full client portal) — distinct from each feature's own narrower leak assertions
 * (PublicProposalViewTest, ClientPortalTest, ...), this is a standing regression guard: seed ONE
 * richly-costed project (BOQ cost fields, supplier pricing, purchase orders, invoice documents,
 * an expense) with deliberately distinctive values, hit every public/portal endpoint that exists,
 * and assert NONE of them ever mention a forbidden field name or the specific cost values seeded
 * — anywhere in the combined response text, not just in the field the code intends to hide.
 *
 * This codebase's actual schema doesn't use the BRD's literal example field names (there is no
 * `supplier_cost`/`markup_percentage`/`internal_pricing_rule_id` column anywhere) — the
 * equivalent real fields this app must never leak are material_unit_cost/labor_unit_cost/
 * other_unit_cost/client_unit_price's cost siblings, boq_item_id (internal linkage),
 * quoted_unit_price/actual_unit_price (supplier pricing), and the profitability rollups
 * (quoted_cost/committed_cost/actual_cost/gross_profit/margin_percent).
 */
class PublicSurfaceLeakAuditTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_FIELDS = [
        'material_unit_cost', 'labor_unit_cost', 'other_unit_cost',
        'source_boq_item_id', 'boq_item_id',
        'quoted_unit_price', 'actual_unit_price', 'quoted_cost', 'committed_cost',
        'actual_cost', 'gross_profit', 'margin_percent',
        'file_fingerprint', 'received_quantity',
        'subtotal', 'markup_total', 'fees_total', 'discount_total',
    ];

    // Deliberately distinctive decimal values seeded below, checked as exact decimal-cast
    // strings (not bare substrings — a bare "555" could coincidentally match a phone number or
    // date elsewhere in Faker-generated content).
    private const FORBIDDEN_VALUES = [
        '911.00', '822.00', '733.00', // BoqItem material/labor/other_unit_cost
        '555.55', '666.66', // PurchaseOrderItem quoted/actual unit price
        '444.44', // InvoiceDocument amount
        '333.33', // ProjectExpense amount
    ];

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
            Permissions::MANAGE_BOQ => true,
            Permissions::MANAGE_PROCUREMENT => true,
            Permissions::MANAGE_PROJECTS => true,
        ]);
    }

    public function test_no_public_or_portal_surface_ever_leaks_cost_margin_or_internal_linkage_fields(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        // Costed BOQ item.
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'material_unit_cost' => '911.00',
            'labor_unit_cost' => '822.00',
            'other_unit_cost' => '733.00',
        ]);

        // Supplier + purchase order with distinct quoted/actual pricing.
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'sent']);
        $order->items()->create([
            'description' => 'Confidential supplier line',
            'unit' => 'm2',
            'quantity' => '10.000',
            'quoted_unit_price' => '555.55',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/purchase-orders/{$order->id}/receive", [
                'items' => [[
                    'id' => $order->items->first()->id,
                    'received_quantity' => '10.000',
                    'actual_unit_price' => '666.66',
                ]],
            ])->assertStatus(200);

        // Invoice document (cost-side, must have NO route on the client portal at all).
        $invoiceFile = UploadedFile::fake()->createWithContent('invoice.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ));
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/invoices", [
                'file' => $invoiceFile,
                'supplier_id' => $supplier->id,
                'invoice_date' => now()->toDateString(),
                'amount' => '444.44',
            ])->assertStatus(201);

        // Expense (internal-only, not surfaced anywhere client-facing).
        ProjectExpense::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'amount' => '333.33',
        ]);

        // Client-safe proposal, sent via the real endpoint (real snapshot_json).
        $proposalId = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');
        $sendResponse = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/proposals/{$proposalId}/send")
            ->assertStatus(200);
        $proposalToken = basename((string) parse_url($sendResponse->json('data.public_url'), PHP_URL_PATH));

        // Client-safe change order — created as 'draft' and sent via the real endpoint (send()
        // rejects a non-draft order), then force-approved directly since the approval flow
        // itself is exercised elsewhere; this test only needs a real signed link.
        $changeOrder = ChangeOrder::factory()->create(['project_id' => $project->id, 'status' => 'draft']);
        ChangeOrderItem::factory()->create([
            'change_order_id' => $changeOrder->id,
            'action' => 'modify',
            'boq_item_id' => null,
            'old_unit_price' => '100.00',
            'new_unit_price' => '150.00',
            'line_delta' => '50.00',
        ]);
        $coSendResponse = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/send")
            ->assertStatus(200);
        $changeOrderToken = basename((string) parse_url($coSendResponse->json('data.public_url'), PHP_URL_PATH));
        $changeOrder->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

        // Client portal link for the whole project.
        $portalToken = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/client-portal-link")
            ->assertStatus(201)
            ->json('data.token');

        $bodies = [];
        $bodies['public_proposal'] = $this->getJson("/api/v1/public/proposals/{$proposalToken}")->assertStatus(200)->getContent();
        $bodies['public_change_order'] = $this->getJson("/api/v1/public/change-orders/{$changeOrderToken}")->assertStatus(200)->getContent();
        $bodies['portal_overview'] = $this->getJson("/api/v1/public/client-portal/{$portalToken}")->assertStatus(200)->getContent();
        $bodies['portal_proposals'] = $this->getJson("/api/v1/public/client-portal/{$portalToken}/proposals")->assertStatus(200)->getContent();
        $bodies['portal_proposal_detail'] = $this->getJson("/api/v1/public/client-portal/{$portalToken}/proposals/{$proposalId}")->assertStatus(200)->getContent();
        $bodies['portal_payments'] = $this->getJson("/api/v1/public/client-portal/{$portalToken}/payments")->assertStatus(200)->getContent();
        $bodies['portal_change_orders'] = $this->getJson("/api/v1/public/client-portal/{$portalToken}/change-orders")->assertStatus(200)->getContent();
        $bodies['portal_media'] = $this->getJson("/api/v1/public/client-portal/{$portalToken}/media")->assertStatus(200)->getContent();

        foreach ($bodies as $surface => $body) {
            foreach (self::FORBIDDEN_FIELDS as $field) {
                $this->assertStringNotContainsString($field, $body, "{$surface} leaked forbidden field '{$field}'");
            }
            foreach (self::FORBIDDEN_VALUES as $value) {
                $this->assertStringNotContainsString($value, $body, "{$surface} leaked forbidden cost value '{$value}'");
            }
        }

        // Invoice/Purchase Order data has NO route on the client portal at all — confirm the
        // supplier's own name (only ever attached to cost-side records here) never surfaces.
        foreach ($bodies as $surface => $body) {
            $this->assertStringNotContainsString($supplier->name, $body, "{$surface} leaked the supplier's name");
        }
    }

    public function test_client_portal_has_no_route_for_invoices_purchase_orders_or_expenses(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        $portalToken = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/client-portal-link")
            ->assertStatus(201)
            ->json('data.token');

        foreach (['invoices', 'purchase-orders', 'expenses'] as $segment) {
            $this->getJson("/api/v1/public/client-portal/{$portalToken}/{$segment}")
                ->assertStatus(404);
        }
    }
}
