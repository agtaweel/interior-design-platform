<?php

namespace Tests\Feature\Procurement;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierPriceHistory;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * BRD "Procurement & Supplier Intelligence": supplier directory + PO lifecycle
 * draft -> sent -> partially_received -> received -> cancelled, quoted vs actual cost kept
 * separate, price history auto-recorded.
 */
class PurchaseOrderTest extends TestCase
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

    /** @return array{0: Organization, 1: Project, 2: User} */
    private function setUpProject(array $permissions = [Permissions::MANAGE_PROCUREMENT => true]): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, $permissions);

        return [$organization, $project, $user];
    }

    public function test_store_creates_a_draft_po_with_items_and_computed_quoted_totals(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/purchase-orders",
            [
                'supplier_id' => $supplier->id,
                'items' => [
                    ['description' => 'Porcelain tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800],
                ],
            ]
        );

        $response->assertStatus(201);
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame('8000.00', $response->json('data.items.0.quoted_total'));
        $this->assertNull($response->json('data.items.0.actual_total'));
    }

    public function test_send_transitions_to_sent_and_records_quoted_price_history(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id]);
        $order->items()->create([
            'description' => 'Porcelain tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/purchase-orders/{$order->id}/send");

        $response->assertStatus(200);
        $this->assertSame('sent', $response->json('data.status'));
        $this->assertNotNull($response->json('data.sent_at'));

        $this->assertDatabaseHas('supplier_price_history', [
            'supplier_id' => $supplier->id,
            'source' => 'quoted',
            'unit_price' => '800.00',
        ]);
    }

    public function test_sending_a_non_draft_po_is_rejected_with_409(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'sent']);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/purchase-orders/{$order->id}/send")
            ->assertStatus(409);
    }

    public function test_receive_partial_delivery_sets_partially_received_and_records_actual_price(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'sent', 'sent_at' => now()]);
        $itemA = $order->items()->create(['description' => 'Tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800]);
        $itemB = $order->items()->create(['description' => 'Grout', 'unit' => 'kg', 'quantity' => 5, 'quoted_unit_price' => 100]);

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/purchase-orders/{$order->id}/receive",
            ['items' => [['id' => $itemA->id, 'received_quantity' => 10, 'actual_unit_price' => 850]]]
        );

        $response->assertStatus(200);
        $this->assertSame('partially_received', $response->json('data.status'));

        $this->assertDatabaseHas('supplier_price_history', [
            'supplier_id' => $supplier->id,
            'source' => 'actual',
            'unit_price' => '850.00',
        ]);

        // Untouched line stays as quoted-only.
        $itemB->refresh();
        $this->assertSame('0.000', $itemB->received_quantity);
    }

    public function test_receive_all_lines_fully_sets_status_to_received(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'sent', 'sent_at' => now()]);
        $item = $order->items()->create(['description' => 'Tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800]);

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/purchase-orders/{$order->id}/receive",
            ['items' => [['id' => $item->id, 'received_quantity' => 10, 'actual_unit_price' => 800]]]
        );

        $response->assertStatus(200);
        $this->assertSame('received', $response->json('data.status'));
        $this->assertSame('8000.00', $response->json('data.items.0.actual_total'));
    }

    public function test_receiving_a_draft_po_is_rejected_with_409(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'draft']);
        $item = $order->items()->create(['description' => 'Tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800]);

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/purchase-orders/{$order->id}/receive",
            ['items' => [['id' => $item->id, 'received_quantity' => 10, 'actual_unit_price' => 800]]]
        )->assertStatus(409);
    }

    public function test_receive_rejects_an_item_id_not_belonging_to_this_po(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'sent', 'sent_at' => now()]);
        $order->items()->create(['description' => 'Tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800]);

        $foreignItem = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id])
            ->items()->create(['description' => 'Other', 'unit' => 'pcs', 'quantity' => 1, 'quoted_unit_price' => 10]);

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/purchase-orders/{$order->id}/receive",
            ['items' => [['id' => $foreignItem->id, 'received_quantity' => 1, 'actual_unit_price' => 10]]]
        )->assertStatus(422);
    }

    public function test_cancel_from_draft_succeeds_but_cannot_cancel_a_received_po(): void
    {
        [$organization, $project, $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);

        $draft = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'draft']);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/purchase-orders/{$draft->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $received = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'received']);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/purchase-orders/{$received->id}/cancel")
            ->assertStatus(409);
    }

    public function test_mutations_without_manage_procurement_permission_are_rejected_with_403(): void
    {
        [$organization, $project, $user] = $this->setUpProject([Permissions::MANAGE_PROCUREMENT => false]);
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/purchase-orders",
            ['supplier_id' => $supplier->id, 'items' => [['description' => 'x', 'unit' => 'm2', 'quantity' => 1, 'quoted_unit_price' => 1]]]
        )->assertStatus(403);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/suppliers')
            ->assertStatus(403);
    }

    public function test_a_member_of_another_organization_cannot_see_or_act_on_this_po(): void
    {
        [$organization, $project] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id]);

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_PROCUREMENT => true]);

        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/purchase-orders/{$order->id}")
            ->assertStatus(404);

        $this->withHeaders($this->authHeader($outsider))
            ->postJson("/api/v1/purchase-orders/{$order->id}/send")
            ->assertStatus(404);
    }

    public function test_supplier_price_history_is_scoped_to_the_supplier(): void
    {
        [$organization, , $user] = $this->setUpProject();
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        SupplierPriceHistory::factory()->count(3)->create(['organization_id' => $organization->id, 'supplier_id' => $supplier->id]);
        SupplierPriceHistory::factory()->create(); // unrelated supplier

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/suppliers/{$supplier->id}/price-history");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }
}
