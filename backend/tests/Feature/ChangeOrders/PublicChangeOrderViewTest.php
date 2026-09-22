<?php

namespace Tests\Feature\ChangeOrders;

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
 * GET /public/change-orders/{token} (PROJECT_CONTEXT.md Sprint 7, PublicChangeOrderResource).
 * Deliberately DIFFERENT exposure rule from BOQ/proposal cost-hiding: price_delta and each
 * item's old/new price + line_delta ARE shown to the client — a price delta is the whole point
 * of what a change order asks them to approve. What must still never leak is internal linkage
 * (boq_item_id) or any BOQ cost fields.
 */
class PublicChangeOrderViewTest extends TestCase
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

    private function boqItemWithPrice(Project $project, string $clientUnitPrice): \App\Models\BoqItem
    {
        $category = \App\Models\BoqCategory::factory()->create(['project_id' => $project->id]);

        return \App\Models\BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'client_unit_price' => $clientUnitPrice,
        ]);
    }

    public function test_public_view_exposes_price_delta_and_item_price_deltas(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '300.00');
        $headers = $this->authHeader($user);

        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/change-orders", [
                'reason' => 'Upgrading finish and removing a line.',
                'items' => [
                    ['action' => 'add', 'description' => 'New Feature Wall', 'quantity' => 2, 'unit' => 'm2', 'new_unit_price' => 500],
                    ['action' => 'modify', 'boq_item_id' => $boqItem->id, 'description' => 'Upgrade tile', 'quantity' => 4, 'unit' => 'm2', 'new_unit_price' => 350],
                ],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/change-orders/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        $response = $this->getJson("/api/v1/public/change-orders/{$token}");

        $response->assertStatus(200)
            ->assertJsonPath('data.reason', 'Upgrading finish and removing a line.')
            ->assertJsonPath('data.status', 'sent');

        // price_delta IS shown (unlike BOQ/proposal cost fields) — this is what the client
        // is approving.
        $this->assertNotNull($response->json('data.price_delta'));
        $this->assertSame(0, bccomp('1200.00', $response->json('data.price_delta'), 2)); // 1000 + 200

        $items = $response->json('data.items');
        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertArrayHasKey('description', $item);
            $this->assertArrayHasKey('quantity', $item);
            $this->assertArrayHasKey('unit', $item);
            $this->assertArrayHasKey('old_unit_price', $item);
            $this->assertArrayHasKey('new_unit_price', $item);
            $this->assertArrayHasKey('line_delta', $item);
            $this->assertNotNull($item['line_delta']);

            // Internal linkage must never leak into the public/client-facing shape.
            $this->assertArrayNotHasKey('boq_item_id', $item);
            $this->assertArrayNotHasKey('id', $item);
        }
    }

    public function test_public_view_never_exposes_boq_item_id_or_internal_linkage_anywhere_in_the_raw_response(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $boqItem = $this->boqItemWithPrice($project, '300.00');
        $headers = $this->authHeader($user);

        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/change-orders", [
                'reason' => 'Remove this line.',
                'items' => [
                    ['action' => 'remove', 'boq_item_id' => $boqItem->id, 'description' => 'Drop it', 'quantity' => 1, 'unit' => 'pcs'],
                ],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/change-orders/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        $response = $this->getJson("/api/v1/public/change-orders/{$token}");

        $response->assertStatus(200);
        $raw = $response->getContent();
        $this->assertStringNotContainsString('boq_item_id', $raw);
        // The raw internal BOQ item's numeric id should not appear as a distinguishable
        // "boq_item_id"-style field; we already assert the key itself is absent above, which is
        // the load-bearing check (the id value alone, e.g. "1", would be too noisy/likely to
        // false-positive against quantity/price fields to assert on directly).
    }

    public function test_public_view_returns_404_for_an_invalid_token(): void
    {
        $this->getJson('/api/v1/public/change-orders/not-a-real-token')
            ->assertStatus(404);
    }

    public function test_public_view_returns_404_for_a_still_draft_change_order_since_no_link_exists_yet(): void
    {
        // A draft change order has no signed_links row at all (only created at send()) — so
        // there is no token to even construct; this documents that a draft is simply
        // unreachable on the public surface, not specially blocked.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $changeOrder = \App\Models\ChangeOrder::factory()->create(['project_id' => $project->id]);

        $this->assertDatabaseCount('signed_links', 0);
        $this->getJson('/api/v1/public/change-orders/some-token-that-was-never-issued')
            ->assertStatus(404);
        $this->assertSame('draft', $changeOrder->fresh()->status);
    }
}
