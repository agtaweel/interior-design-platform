<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 7 "Apply" step (ChangeOrderApplyService) — the highest-risk part of
 * the sprint: the one controlled, audited exception to Sprint 5's contract-immutability rule.
 * Everything here runs inside one DB::transaction() per the service's docblock; these tests pin
 * every documented side effect precisely (not just "it returns 200").
 */
class ChangeOrderApplyTest extends TestCase
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

    private function boqItemWithPrice(Project $project, string $clientUnitPrice, string $quantity = '7.00'): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'client_unit_price' => $clientUnitPrice,
            'quantity' => $quantity,
            'material_unit_cost' => '50.00',
            'labor_unit_cost' => '20.00',
            'other_unit_cost' => '5.00',
        ]);
    }

    private function apply(User $user, ChangeOrder $changeOrder): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/apply");
    }

    private function approvedChangeOrderWithItems(Project $project, array $items, ?string $timelineDeltaDays = null): ChangeOrder
    {
        $changeOrder = ChangeOrder::factory()->create([
            'project_id' => $project->id,
            'status' => 'approved',
            'sent_at' => now(),
            'approved_at' => now(),
            'timeline_delta_days' => $timelineDeltaDays,
        ]);

        $total = '0.00';
        foreach ($items as $itemAttrs) {
            ChangeOrderItem::factory()->create(array_merge(['change_order_id' => $changeOrder->id], $itemAttrs));
            $total = bcadd($total, (string) $itemAttrs['line_delta'], 2);
        }
        $changeOrder->forceFill(['price_delta' => $total])->save();

        return $changeOrder->fresh(['items']);
    }

    // --- 'add' item ---

    public function test_apply_add_item_creates_a_new_boq_item_with_zeroed_cost_fields(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        Contract::factory()->create(['project_id' => $project->id]);

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'New Extra Cabinet',
            'quantity' => '3.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '400.00',
            'line_delta' => '1200.00',
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);

        $newItem = BoqItem::where('name', 'New Extra Cabinet')->orWhere('description', 'New Extra Cabinet')->first();
        $this->assertNotNull($newItem, 'expected a new BoqItem to be created from the add item');
        $this->assertSame($project->id, $newItem->project_id);
        $this->assertSame(0, bccomp('400.00', (string) $newItem->client_unit_price, 2));
        $this->assertSame(0, bccomp('3.00', (string) $newItem->quantity, 2));
        $this->assertSame(0, bccomp('0.00', (string) $newItem->material_unit_cost, 2));
        $this->assertSame(0, bccomp('0.00', (string) $newItem->labor_unit_cost, 2));
        $this->assertSame(0, bccomp('0.00', (string) $newItem->other_unit_cost, 2));
        $this->assertNull($newItem->archived_at);
        $this->assertNotNull($newItem->category_id);
    }

    // --- 'remove' item ---

    public function test_apply_remove_item_archives_but_does_not_hard_delete_the_boq_item(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        Contract::factory()->create(['project_id' => $project->id]);
        $boqItem = $this->boqItemWithPrice($project, '300.00');

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'remove',
            'boq_item_id' => $boqItem->id,
            'description' => 'Remove line',
            'quantity' => '7.00',
            'unit' => 'm2',
            'old_unit_price' => '300.00',
            'new_unit_price' => null,
            'line_delta' => '-2100.00',
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);

        // Row still exists in the DB (soft-archived, never hard-deleted).
        $this->assertDatabaseHas('boq_items', ['id' => $boqItem->id]);
        $fresh = $boqItem->fresh();
        $this->assertNotNull($fresh->archived_at);
    }

    // --- 'modify' item ---

    public function test_apply_modify_item_updates_only_client_unit_price_not_cost_fields_or_quantity(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        Contract::factory()->create(['project_id' => $project->id]);
        $boqItem = $this->boqItemWithPrice($project, '200.00', '7.00');

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'modify',
            'boq_item_id' => $boqItem->id,
            'description' => 'Modify line',
            // Deliberately different from the boq_item's real quantity (7.00), to prove modify
            // never touches it.
            'quantity' => '99.00',
            'unit' => 'm2',
            'old_unit_price' => '200.00',
            'new_unit_price' => '250.00',
            'line_delta' => '350.00', // 7 * (250-200) as originally computed at creation time
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);

        $fresh = $boqItem->fresh();
        $this->assertSame(0, bccomp('250.00', (string) $fresh->client_unit_price, 2));
        // Quantity and cost fields untouched.
        $this->assertSame(0, bccomp('7.00', (string) $fresh->quantity, 2));
        $this->assertSame(0, bccomp('50.00', (string) $fresh->material_unit_cost, 2));
        $this->assertSame(0, bccomp('20.00', (string) $fresh->labor_unit_cost, 2));
        $this->assertSame(0, bccomp('5.00', (string) $fresh->other_unit_cost, 2));
    }

    // --- contract_value delta application ---

    public function test_apply_increases_contract_value_by_exactly_the_positive_price_delta(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = Contract::factory()->create(['project_id' => $project->id, 'contract_value' => '100000.00']);

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'Extra work',
            'quantity' => '3.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '417.33',
            'line_delta' => '1251.99',
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);

        $fresh = $contract->fresh();
        $this->assertSame(0, bccomp('101251.99', (string) $fresh->contract_value, 2));
    }

    public function test_apply_decreases_contract_value_by_exactly_the_negative_price_delta(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = Contract::factory()->create(['project_id' => $project->id, 'contract_value' => '100000.00']);
        $boqItem = $this->boqItemWithPrice($project, '333.33');

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'remove',
            'boq_item_id' => $boqItem->id,
            'description' => 'Remove line',
            'quantity' => '7.00',
            'unit' => 'm2',
            'old_unit_price' => '333.33',
            'new_unit_price' => null,
            'line_delta' => '-2333.31',
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);

        $fresh = $contract->fresh();
        $this->assertSame(0, bccomp('97666.69', (string) $fresh->contract_value, 2));
    }

    // --- end_date extension ---

    public function test_apply_extends_contract_end_date_by_timeline_delta_days_when_end_date_is_set(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'end_date' => '2027-01-01',
        ]);

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'Extra work',
            'quantity' => '1.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '100.00',
            'line_delta' => '100.00',
        ]], timelineDeltaDays: '10');

        $this->apply($user, $changeOrder)->assertStatus(200);

        $fresh = $contract->fresh();
        $this->assertSame('2027-01-11', $fresh->end_date->toDateString());
    }

    public function test_apply_does_not_invent_an_end_date_when_none_was_set(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'end_date' => null,
        ]);

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'Extra work',
            'quantity' => '1.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '100.00',
            'line_delta' => '100.00',
        ]], timelineDeltaDays: '10');

        $response = $this->apply($user, $changeOrder);

        $response->assertStatus(200); // must not crash
        $this->assertNull($contract->fresh()->end_date);
    }

    // --- no contract ---

    public function test_apply_fails_422_when_project_has_no_contract_and_mutates_nothing(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '200.00');

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'modify',
            'boq_item_id' => $boqItem->id,
            'description' => 'Modify line',
            'quantity' => '7.00',
            'unit' => 'm2',
            'old_unit_price' => '200.00',
            'new_unit_price' => '250.00',
            'line_delta' => '350.00',
        ]]);

        $response = $this->apply($user, $changeOrder);

        $response->assertStatus(422)->assertJsonPath('error.code', 'CHANGE_ORDER_NO_CONTRACT');

        // Nothing partially applied: BOQ item untouched, change order still 'approved'.
        $this->assertSame(0, bccomp('200.00', (string) $boqItem->fresh()->client_unit_price, 2));
        $this->assertSame('approved', $changeOrder->fresh()->status);
        $this->assertNull($changeOrder->fresh()->applied_at);
        $this->assertDatabaseCount('contracts', 0);
    }

    // --- re-apply guard ---

    public function test_reapplying_an_already_applied_change_order_fails_409_and_does_not_double_apply(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = Contract::factory()->create(['project_id' => $project->id, 'contract_value' => '100000.00']);

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'Extra work',
            'quantity' => '1.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '500.00',
            'line_delta' => '500.00',
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);
        $this->assertSame(0, bccomp('100500.00', (string) $contract->fresh()->contract_value, 2));

        // Second attempt: status is now 'applied', not 'approved' -> 409, not a second apply.
        $second = $this->apply($user, $changeOrder->fresh());
        $second->assertStatus(409)->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_APPROVED');

        // Contract value unchanged by the second (rejected) attempt.
        $this->assertSame(0, bccomp('100500.00', (string) $contract->fresh()->contract_value, 2));
        $this->assertDatabaseCount('boq_items', 1); // only the single 'add' item's BoqItem, not two
    }

    // --- audit trail (Definition of Done: auditable commercial mutation) ---

    public function test_apply_leaves_an_audit_trail_on_both_the_change_order_and_the_contract(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $contract = Contract::factory()->create(['project_id' => $project->id, 'contract_value' => '100000.00']);

        $changeOrder = $this->approvedChangeOrderWithItems($project, [[
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'Extra work',
            'quantity' => '1.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '500.00',
            'line_delta' => '500.00',
        ]]);

        $this->apply($user, $changeOrder)->assertStatus(200);

        // ChangeOrder is Auditable -> its status/applied_at transition is logged.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'ChangeOrder',
            'entity_id' => $changeOrder->id,
            'action' => 'updated',
        ]);

        // Contract is Auditable -> the contract_value mutation is logged too, so the change
        // in commercial value is traceable, not a silent number change.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'Contract',
            'entity_id' => $contract->id,
            'action' => 'updated',
        ]);
    }
}
