<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Models\BoqCatalogItemAlias;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BOQ Master Catalog + Standard Templates — GET /boq-catalog/categories, /boq-catalog/search,
 * and the catalog mutation endpoints, across both the tenant (/boq-catalog/*) and platform
 * (/platform/boq-catalog/*) mounts. See BoqCatalogController's docblock for the dual-mount
 * convention this exercises.
 */
class BoqCatalogTreeTest extends TestCase
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

    private function platformOwner(): User
    {
        return User::factory()->create(['is_platform_owner' => true]);
    }

    public function test_tenant_tree_merges_the_organizations_own_catalog_with_the_global_catalog(): void
    {
        $organization = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $globalCategory = BoqCatalogCategory::factory()->system()->create(['name' => 'Global Flooring']);
        BoqCatalogItem::factory()->system()->create(['category_id' => $globalCategory->id, 'name' => 'Global Tile']);

        $ownCategory = BoqCatalogCategory::factory()->create(['organization_id' => $organization->id, 'name' => 'My Custom Category']);
        BoqCatalogItem::factory()->create(['category_id' => $ownCategory->id, 'name' => 'My Custom Item']);

        $foreignCategory = BoqCatalogCategory::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Foreign Category']);
        BoqCatalogItem::factory()->create(['category_id' => $foreignCategory->id, 'name' => 'Foreign Item']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/boq-catalog/categories');

        $response->assertStatus(200);
        $names = collect($response->json('data.categories'))->pluck('name');
        $this->assertTrue($names->contains('Global Flooring'));
        $this->assertTrue($names->contains('My Custom Category'));
        $this->assertFalse($names->contains('Foreign Category'));
    }

    public function test_platform_mount_only_ever_sees_the_global_catalog(): void
    {
        $organization = Organization::factory()->create();
        $globalCategory = BoqCatalogCategory::factory()->system()->create(['name' => 'Global Plumbing']);
        BoqCatalogItem::factory()->system()->create(['category_id' => $globalCategory->id]);
        BoqCatalogCategory::factory()->create(['organization_id' => $organization->id, 'name' => 'Some Orgs Category']);

        $owner = $this->platformOwner();
        $response = $this->withHeaders($this->authHeader($owner))->getJson('/api/v1/platform/boq-catalog/categories');

        $response->assertStatus(200);
        $names = collect($response->json('data.categories'))->pluck('name');
        $this->assertTrue($names->contains('Global Plumbing'));
        $this->assertFalse($names->contains('Some Orgs Category'));
    }

    public function test_search_matches_by_alias(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $category = BoqCatalogCategory::factory()->create(['organization_id' => $organization->id]);
        $item = BoqCatalogItem::factory()->create(['category_id' => $category->id, 'name' => 'WC Installation']);
        BoqCatalogItemAlias::factory()->create(['catalog_item_id' => $item->id, 'alias_en' => 'toilet']);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/boq-catalog/search?q=toilet');

        $response->assertStatus(200);
        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($item->id));
    }

    public function test_a_member_without_manage_boq_can_read_but_not_write_the_catalog(): void
    {
        $organization = Organization::factory()->create();
        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        $this->withHeaders($headers)->getJson('/api/v1/boq-catalog/categories')->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson('/api/v1/boq-catalog/categories', ['name' => 'Should Fail'])
            ->assertStatus(403);
        $this->assertDatabaseMissing('boq_catalog_categories', ['name' => 'Should Fail']);
    }

    public function test_tenant_mount_cannot_mutate_a_global_system_category(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $globalCategory = BoqCatalogCategory::factory()->system()->create(['name' => 'Protected Global']);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/boq-catalog/categories/{$globalCategory->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Protected Global', $globalCategory->fresh()->name);
    }

    public function test_platform_mount_can_create_and_mutate_global_categories_and_items(): void
    {
        $owner = $this->platformOwner();
        $headers = $this->authHeader($owner);

        $create = $this->withHeaders($headers)->postJson('/api/v1/platform/boq-catalog/categories', ['name' => 'New Global Trade']);
        $create->assertStatus(201);
        $categoryId = $create->json('data.id');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/platform/boq-catalog/categories/{$categoryId}", ['name' => 'Renamed Global Trade'])
            ->assertStatus(200);

        $this->assertDatabaseHas('boq_catalog_categories', ['id' => $categoryId, 'name' => 'Renamed Global Trade', 'organization_id' => null]);
    }

    public function test_a_non_platform_owner_cannot_use_the_platform_catalog_mount(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/platform/boq-catalog/categories')
            ->assertStatus(403);
    }
}
