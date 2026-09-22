<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 7 "Create" step -> POST /projects/{id}/change-orders:
 * line_delta computation per action type (ChangeOrderService::buildItem()/computeLineDelta()),
 * the "look up old_unit_price from the referenced boq_item's current client_unit_price rather
 * than trusting client input" rule, price_delta as the sum of item line_deltas, and the
 * per-action validation rules (StoreChangeOrderRequest).
 */
class ChangeOrderCreationTest extends TestCase
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

    private function boqItemWithPrice(Project $project, string $clientUnitPrice): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'client_unit_price' => $clientUnitPrice,
        ]);
    }

    private function create(array $headers, Project $project, array $body): TestResponse
    {
        return $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/change-orders", $body);
    }

    // --- line_delta per action type ---

    public function test_add_action_computes_positive_line_delta(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Client requested an additional item.',
            'items' => [
                ['action' => 'add', 'description' => 'Extra Wall Socket', 'quantity' => 2, 'unit' => 'pcs', 'new_unit_price' => 500],
            ],
        ]);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');
        $this->assertSame(0, bccomp('1000.00', $item['line_delta'], 2));
        $this->assertSame(0, bccomp('1000.00', $response->json('data.price_delta'), 2));
        $this->assertNull($item['boq_item_id']);
        $this->assertNull($item['old_unit_price']);
    }

    public function test_remove_action_computes_negative_line_delta_from_the_live_boq_price(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '300.00');

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Removing this line at client request.',
            'items' => [
                ['action' => 'remove', 'boq_item_id' => $boqItem->id, 'description' => 'Remove tile line', 'quantity' => 3, 'unit' => 'm2'],
            ],
        ]);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');

        $this->assertSame(0, bccomp('300.00', $item['old_unit_price'], 2));
        $this->assertSame(0, bccomp('-900.00', $item['line_delta'], 2));
        $this->assertSame(0, bccomp('-900.00', $response->json('data.price_delta'), 2));
        $this->assertNull($item['new_unit_price']);
    }

    /**
     * Regression test for a real bug found in QA: old_unit_price was only derived server-side
     * when the client omitted it — a client-supplied value was trusted verbatim. Since this
     * figure feeds line_delta -> price_delta -> contracts.contract_value on apply, it must never
     * be client-controlled at all. The request is now rejected outright (422) rather than the
     * server silently substituting the correct value, so a bad-faith or buggy caller gets a
     * clear signal instead of a masked correction.
     */
    public function test_client_supplied_old_unit_price_is_rejected_outright(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '300.00');

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Removing this line at client request.',
            'items' => [
                ['action' => 'remove', 'boq_item_id' => $boqItem->id, 'description' => 'Remove tile line', 'quantity' => 3, 'unit' => 'm2', 'old_unit_price' => 999.00],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertArrayHasKey('items.0.old_unit_price', $response->json('error.details'));
    }

    public function test_remove_action_falls_back_to_boq_item_price_when_old_unit_price_omitted(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '150.00');

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Removing this line at client request.',
            'items' => [
                ['action' => 'remove', 'boq_item_id' => $boqItem->id, 'description' => 'Remove item', 'quantity' => 4, 'unit' => 'pcs'],
            ],
        ]);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');
        $this->assertSame(0, bccomp('150.00', $item['old_unit_price'], 2));
        $this->assertSame(0, bccomp('-600.00', $item['line_delta'], 2));
    }

    public function test_modify_action_price_increase_computes_positive_line_delta(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '200.00');

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Upgrading finish grade.',
            'items' => [
                ['action' => 'modify', 'boq_item_id' => $boqItem->id, 'description' => 'Upgrade tile', 'quantity' => 4, 'unit' => 'm2', 'new_unit_price' => 250],
            ],
        ]);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');
        $this->assertSame(0, bccomp('200.00', $item['old_unit_price'], 2));
        $this->assertSame(0, bccomp('250.00', $item['new_unit_price'], 2));
        $this->assertSame(0, bccomp('200.00', $item['line_delta'], 2)); // 4 * (250 - 200)
    }

    public function test_modify_action_price_decrease_computes_negative_line_delta(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '300.00');

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Downgrading to a cheaper finish.',
            'items' => [
                ['action' => 'modify', 'boq_item_id' => $boqItem->id, 'description' => 'Downgrade tile', 'quantity' => 5, 'unit' => 'm2', 'new_unit_price' => 250],
            ],
        ]);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');
        $this->assertSame(0, bccomp('-250.00', $item['line_delta'], 2)); // 5 * (250 - 300)
    }

    public function test_price_delta_sums_a_mix_of_all_three_action_types(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $removeItem = $this->boqItemWithPrice($project, '300.00'); // remove: -900.00
        $modifyItem = $this->boqItemWithPrice($project, '200.00'); // modify: +200.00 (4 * 50)

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Mixed change: add a new line, remove one, modify another.',
            'items' => [
                ['action' => 'add', 'description' => 'New Line', 'quantity' => 2, 'unit' => 'pcs', 'new_unit_price' => 500], // +1000.00
                ['action' => 'remove', 'boq_item_id' => $removeItem->id, 'description' => 'Drop line', 'quantity' => 3, 'unit' => 'm2'],
                ['action' => 'modify', 'boq_item_id' => $modifyItem->id, 'description' => 'Upgrade line', 'quantity' => 4, 'unit' => 'm2', 'new_unit_price' => 250],
            ],
        ]);

        $response->assertStatus(201);
        // 1000.00 - 900.00 + 200.00 = 300.00
        $this->assertSame(0, bccomp('300.00', $response->json('data.price_delta'), 2));
        $this->assertCount(3, $response->json('data.items'));
    }

    // --- validation ---

    public function test_add_action_rejects_a_boq_item_id(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '100.00');

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Bad payload.',
            'items' => [
                ['action' => 'add', 'boq_item_id' => $boqItem->id, 'description' => 'New Line', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 100],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('change_orders', 0);
    }

    public function test_add_action_requires_description_quantity_unit_and_new_unit_price(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Missing fields.',
            'items' => [
                ['action' => 'add'],
            ],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('error.details');
        foreach (['items.0.description', 'items.0.quantity', 'items.0.unit', 'items.0.new_unit_price'] as $field) {
            $this->assertArrayHasKey($field, $errors, "expected a validation error on {$field}");
        }
    }

    public function test_remove_action_requires_a_boq_item_id(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Missing boq_item_id.',
            'items' => [
                ['action' => 'remove', 'description' => 'Remove something', 'quantity' => 1, 'unit' => 'pcs'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_modify_action_requires_a_boq_item_id(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Missing boq_item_id.',
            'items' => [
                ['action' => 'modify', 'description' => 'Modify something', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 100],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_boq_item_id_from_another_project_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $foreignItem = $this->boqItemWithPrice($projectB, '100.00');

        $response = $this->create($this->authHeader($user), $projectA, [
            'reason' => 'Trying to reference another project\'s BOQ item.',
            'items' => [
                ['action' => 'remove', 'boq_item_id' => $foreignItem->id, 'description' => 'Remove', 'quantity' => 1, 'unit' => 'pcs'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('change_orders', 0);
    }

    public function test_boq_item_id_referencing_an_archived_item_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '100.00');
        $boqItem->update(['archived_at' => now()]);

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Referencing an archived item.',
            'items' => [
                ['action' => 'remove', 'boq_item_id' => $boqItem->id, 'description' => 'Remove', 'quantity' => 1, 'unit' => 'pcs'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_created_change_order_starts_as_draft_with_an_auto_generated_number(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->create($this->authHeader($user), $project, [
            'reason' => 'Adding a line.',
            'items' => [
                ['action' => 'add', 'description' => 'New Line', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 100],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');
        $this->assertMatchesRegularExpression('/^CO-\d{5}$/', $response->json('data.number'));

        $changeOrder = ChangeOrder::find($response->json('data.id'));
        $this->assertSame($project->id, $changeOrder->project_id);
        $this->assertSame($user->id, $changeOrder->requested_by);
    }
}
