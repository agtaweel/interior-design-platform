<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RBAC coverage for Sprint 7's change-orders surface, per ChangeOrderController's docblock:
 * reads (index/show) require only an active membership; mutations (store/update/send/apply)
 * require Permissions::MANAGE_BOQ. Per PROJECT_CONTEXT.md's explicit reasoning, price_delta is a
 * *pricing* concept (same tier as BOQ/pricing-rule/proposal/contract data), NOT a *collected-
 * money* concept — reads deliberately do NOT gate behind VIEW_FINANCIALS. This is pinned
 * explicitly with a member who has view_financials but NOT manage_boq, confirming reads aren't
 * over-gated (a real risk given Sprint 6 introduced VIEW_FINANCIALS specifically for money-
 * adjacent data and a lazy implementation could have defaulted to the stricter gate).
 */
class ChangeOrderRbacTest extends TestCase
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

    public function test_read_only_member_can_list_and_view_but_not_create_update_send_or_apply(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);

        // Seed the existing draft directly via the model factory (same deliberate choice as
        // ProposalRbacTest — see that class's docblock for the documented Sanctum-guard-caching
        // test-harness quirk this sidesteps).
        $id = ChangeOrder::factory()->create(['project_id' => $project->id])->id;

        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        // --- Reads succeed ---
        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/change-orders")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/change-orders/{$id}")
            ->assertStatus(200);

        // --- Writes are forbidden ---
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/change-orders", [
                'reason' => 'nope',
                'items' => [['action' => 'add', 'description' => 'X', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 1]],
            ])
            ->assertStatus(403);
        $this->assertDatabaseCount('change_orders', 1); // only the seeded one

        $this->withHeaders($headers)
            ->patchJson("/api/v1/change-orders/{$id}", ['reason' => 'nope'])
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->postJson("/api/v1/change-orders/{$id}/send")
            ->assertStatus(403);
        $this->assertDatabaseCount('signed_links', 0);

        $this->withHeaders($headers)
            ->postJson("/api/v1/change-orders/{$id}/apply")
            ->assertStatus(403);
    }

    public function test_member_with_only_view_financials_can_still_read_change_orders(): void
    {
        // The pivotal assertion: view_financials is NOT required (nor is manage_boq) for reads
        // — an active membership plus this permission alone (deliberately excluding manage_boq)
        // must be enough to list/view, proving reads aren't accidentally over-gated behind the
        // money-visibility permission the way Sprint 6 payments are.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $id = ChangeOrder::factory()->create(['project_id' => $project->id])->id;

        $user = $this->memberWithPermissions($organization, [Permissions::VIEW_FINANCIALS => true]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/change-orders")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/change-orders/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id);

        // But still cannot write with only view_financials (manage_boq required for mutations).
        $this->withHeaders($headers)
            ->patchJson("/api/v1/change-orders/{$id}", ['reason' => 'nope'])
            ->assertStatus(403);
    }

    public function test_member_with_manage_boq_can_perform_every_change_order_write_operation(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $create = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/change-orders", [
                'reason' => 'Adding a line.',
                'items' => [['action' => 'add', 'description' => 'X', 'quantity' => 1, 'unit' => 'pcs', 'new_unit_price' => 100]],
            ])
            ->assertStatus(201);
        $id = $create->json('data.id');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/change-orders/{$id}", ['reason' => 'Updated'])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson("/api/v1/change-orders/{$id}/send")
            ->assertStatus(200);

        \App\Models\Contract::factory()->create(['project_id' => $project->id]);
        ChangeOrder::find($id)->update(['status' => 'approved', 'approved_at' => now()]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/change-orders/{$id}/apply")
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_to_internal_change_order_routes_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);

        $this->getJson("/api/v1/projects/{$project->id}/change-orders")->assertStatus(401);
        $this->postJson("/api/v1/projects/{$project->id}/change-orders")->assertStatus(401);
    }

    /**
     * Public endpoints have no permission gate (the signed token is the sole authorization
     * mechanism) but must carry the `public-links` rate limiter — same route-registration-level
     * check as ProposalRbacTest, for the same reasons documented there.
     */
    public function test_all_three_public_change_order_routes_carry_the_public_links_rate_limiter(): void
    {
        $expectedUris = [
            ['GET', 'api/v1/public/change-orders/{token}'],
            ['POST', 'api/v1/public/change-orders/{token}/approve'],
            ['POST', 'api/v1/public/change-orders/{token}/reject'],
        ];

        foreach ($expectedUris as [$method, $uri]) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
            );

            $this->assertNotNull($route, "Route not found: {$method} {$uri}");
            $this->assertContains(
                'throttle:public-links',
                $route->gatherMiddleware(),
                "{$method} {$uri} is missing the public-links throttle middleware"
            );
            $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertNotContains('tenant', $route->gatherMiddleware());
        }
    }
}
