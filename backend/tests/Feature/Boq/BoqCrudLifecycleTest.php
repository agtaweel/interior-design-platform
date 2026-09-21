<?php

namespace Tests\Feature\Boq;

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
 * Sprint 2 BOQ: full CRUD lifecycle via the real HTTP surface — create a root category, a
 * nested child category under it, an item, update the item, then archive it and confirm the
 * archive is a soft delete (excluded from the default GET .../boq view and its totals, but
 * present when ?include_archived=1 is passed). Mirrors the smoke-test style of
 * tests/Feature/Projects/ClientPropertyProjectApiTest.php.
 */
class BoqCrudLifecycleTest extends TestCase
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

    public function test_full_boq_crud_lifecycle_via_the_api(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        // --- Root category ---
        $createRoot = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/categories", [
            'name' => 'Flooring',
        ]);
        $createRoot->assertStatus(201)->assertJsonPath('data.name', 'Flooring')->assertJsonPath('data.parent_id', null);
        $rootId = $createRoot->json('data.id');
        $this->assertDatabaseHas('boq_categories', ['id' => $rootId, 'project_id' => $project->id, 'parent_id' => null]);

        // --- Nested child category ---
        $createChild = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/categories", [
            'name' => 'Tiling',
            'parent_id' => $rootId,
        ]);
        $createChild->assertStatus(201)->assertJsonPath('data.name', 'Tiling')->assertJsonPath('data.parent_id', $rootId);
        $childId = $createChild->json('data.id');
        $this->assertDatabaseHas('boq_categories', ['id' => $childId, 'parent_id' => $rootId]);

        // --- Item under the nested category ---
        $createItem = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/items", [
            'category_id' => $childId,
            'name' => 'Porcelain Tile 60x60',
            'quantity' => 10,
            'unit' => 'm2',
            'material_unit_cost' => 100,
            'labor_unit_cost' => 50,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ]);
        $createItem->assertStatus(201)
            ->assertJsonPath('data.category_id', $childId)
            ->assertJsonPath('data.direct_cost', '1500.00')
            ->assertJsonPath('data.client_total', '2000.00')
            ->assertJsonPath('data.archived_at', null);
        $itemId = $createItem->json('data.id');
        $this->assertDatabaseHas('boq_items', ['id' => $itemId, 'project_id' => $project->id, 'category_id' => $childId]);

        // --- Update item ---
        $update = $this->withHeaders($headers)->patchJson("/api/v1/boq/items/{$itemId}", [
            'quantity' => 20,
            'name' => 'Porcelain Tile 60x60 (Grade A)',
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.name', 'Porcelain Tile 60x60 (Grade A)')
            ->assertJsonPath('data.direct_cost', '3000.00')
            ->assertJsonPath('data.client_total', '4000.00');

        // --- BOQ tree reflects the item before archiving ---
        $treeBeforeArchive = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq");
        $treeBeforeArchive->assertStatus(200);
        $childNode = collect($treeBeforeArchive->json('data.categories.0.children'))->firstWhere('id', $childId);
        $this->assertNotNull($childNode, 'expected the nested child category to appear in the tree');
        $this->assertCount(1, $childNode['items']);
        $this->assertSame('3000.00', $childNode['subtotal']['direct_cost']);
        $this->assertSame('4000.00', $treeBeforeArchive->json('data.grand_total.client_total'));

        // --- Archive (soft delete) ---
        $archive = $this->withHeaders($headers)->deleteJson("/api/v1/boq/items/{$itemId}");
        $archive->assertStatus(200);
        $this->assertNotNull($archive->json('data.archived_at'));
        $this->assertDatabaseHas('boq_items', ['id' => $itemId]);
        $this->assertNotNull(\App\Models\BoqItem::find($itemId)->archived_at);

        // --- Default GET excludes the archived item and its totals ---
        $treeAfterArchive = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq");
        $treeAfterArchive->assertStatus(200);
        $childNodeAfter = collect($treeAfterArchive->json('data.categories.0.children'))->firstWhere('id', $childId);
        $this->assertCount(0, $childNodeAfter['items']);
        $this->assertSame('0.00', $childNodeAfter['subtotal']['direct_cost']);
        $this->assertSame('0.00', $treeAfterArchive->json('data.grand_total.direct_cost'));
        $this->assertSame('0.00', $treeAfterArchive->json('data.grand_total.client_total'));

        // --- include_archived=1 brings it back ---
        $treeIncludingArchived = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq?include_archived=1");
        $treeIncludingArchived->assertStatus(200);
        $childNodeIncluded = collect($treeIncludingArchived->json('data.categories.0.children'))->firstWhere('id', $childId);
        $this->assertCount(1, $childNodeIncluded['items']);
        $this->assertSame('3000.00', $childNodeIncluded['subtotal']['direct_cost']);
        $this->assertSame('4000.00', $treeIncludingArchived->json('data.grand_total.client_total'));
        $this->assertTrue($treeIncludingArchived->json('data.include_archived'));

        // --- Archiving twice is idempotent (archived_at doesn't change) ---
        $firstArchivedAt = \App\Models\BoqItem::find($itemId)->archived_at;
        $this->withHeaders($headers)->deleteJson("/api/v1/boq/items/{$itemId}")->assertStatus(200);
        $this->assertTrue($firstArchivedAt->equalTo(\App\Models\BoqItem::find($itemId)->archived_at));
    }

    public function test_creating_a_category_with_a_parent_from_another_project_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $categoryInB = \App\Models\BoqCategory::factory()->create(['project_id' => $projectB->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$projectA->id}/boq/categories", [
                'name' => 'Cross-project category',
                'parent_id' => $categoryInB->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['parent_id']]]);
    }

    public function test_creating_an_item_requires_a_category_belonging_to_the_same_project(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $categoryInB = \App\Models\BoqCategory::factory()->create(['project_id' => $projectB->id]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$projectA->id}/boq/items", [
                'category_id' => $categoryInB->id,
                'name' => 'Should fail',
                'quantity' => 1,
                'unit' => 'pcs',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['category_id']]]);
    }
}
