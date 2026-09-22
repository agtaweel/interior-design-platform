<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Extends the tenant-isolation guarantee (mirrors ProposalTenantIsolationTest) to Sprint 7's
 * change orders surface. change_order_items is TWO-HOP indirectly scoped (change_order_id ->
 * change_orders.project_id -> projects.organization_id, same as proposal_items) — there is no
 * standalone route to list change_order_items, so proving org A cannot reach org B's
 * change_order (via index/show) is what proves org A cannot reach org B's change_order_items
 * either, since items are only ever exposed embedded inside their parent's response. change_orders
 * itself is ONE-HOP (project_id -> projects.organization_id), verified directly.
 */
class ChangeOrderTenantIsolationTest extends TestCase
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

    /** Builds org B's draft change order directly (not via HTTP — we're org A in these tests). */
    private function changeOrderWithItemsIn(Project $project): ChangeOrder
    {
        $changeOrder = ChangeOrder::factory()->create(['project_id' => $project->id]);
        ChangeOrderItem::factory()->create([
            'change_order_id' => $changeOrder->id,
            'description' => 'Org B Secret Change Order Line',
        ]);

        return $changeOrder;
    }

    public function test_cannot_list_another_organizations_project_change_orders(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $this->changeOrderWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectB->id}/change-orders")
            ->assertStatus(404);
    }

    public function test_cannot_create_a_change_order_for_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/change-orders", [
                'reason' => 'Trying to sneak into another org.',
                'items' => [
                    ['action' => 'add', 'description' => 'X', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 1],
                ],
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('change_orders', 0);
    }

    public function test_cannot_view_another_organizations_change_order_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $changeOrderB = $this->changeOrderWithItemsIn($projectB);

        $response = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/change-orders/{$changeOrderB->id}");

        $response->assertStatus(404);
        // The two-hop-scoped secret line item must not leak into the 404 body either.
        $this->assertStringNotContainsString('Org B Secret Change Order Line', $response->getContent());
    }

    public function test_cannot_patch_another_organizations_change_order_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $changeOrderB = $this->changeOrderWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->patchJson("/api/v1/change-orders/{$changeOrderB->id}", ['reason' => 'Hacked'])
            ->assertStatus(404);

        $this->assertNotSame('Hacked', $changeOrderB->fresh()->reason);
    }

    public function test_cannot_send_another_organizations_change_order_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $changeOrderB = $this->changeOrderWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/change-orders/{$changeOrderB->id}/send")
            ->assertStatus(404);

        $this->assertSame('draft', $changeOrderB->fresh()->status);
        $this->assertDatabaseCount('signed_links', 0);
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_cannot_apply_another_organizations_change_order_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        \App\Models\Contract::factory()->create(['project_id' => $projectB->id]);
        $changeOrderB = $this->changeOrderWithItemsIn($projectB);
        $changeOrderB->update(['status' => 'approved', 'sent_at' => now(), 'approved_at' => now()]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/change-orders/{$changeOrderB->id}/apply")
            ->assertStatus(404);

        $this->assertSame('approved', $changeOrderB->fresh()->status);
        $this->assertNull($changeOrderB->fresh()->applied_at);
    }

    public function test_org_a_user_sees_only_org_as_own_change_orders_when_both_orgs_have_them(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectA = $this->projectIn($orgA);
        $projectB = $this->projectIn($orgB);
        $this->changeOrderWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectA->id}/change-orders", [
                'reason' => 'Org A own change order.',
                'items' => [
                    ['action' => 'add', 'description' => 'X', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 1],
                ],
            ])
            ->assertStatus(201);

        Auth::forgetGuards();

        $index = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectA->id}/change-orders")
            ->assertStatus(200);

        $this->assertCount(1, $index->json('data'));
        $this->assertDatabaseCount('change_orders', 2);
    }
}
