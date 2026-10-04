<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCatalogItem;
use App\Models\BoqTemplate;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
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
 * BOQ Master Catalog + Standard Templates — POST /projects/{project}/boq/template-preview's
 * merge-conflict logic (BoqTemplatePreviewService). Templates, packages, and room templates are
 * all just BoqTemplate rows, so "two templates contribute the same catalog item" covers every
 * composition case the spec describes.
 */
class BoqTemplatePreviewMergeTest extends TestCase
{
    use RefreshDatabase;

    private function manageBoqUser(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [Permissions::MANAGE_BOQ => true]]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active',
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

    private function publishedVersion(Organization $organization): BoqTemplateVersion
    {
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $version = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id]);
        $template->update(['active_version_id' => $version->id]);

        return $version->fresh('template');
    }

    private function flatCategoryIds(array $categories): array
    {
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = $category['id'];
            $ids = array_merge($ids, $this->flatCategoryIds($category['children']));
        }

        return $ids;
    }

    private function flatItems(array $categories): array
    {
        $items = [];
        foreach ($categories as $category) {
            $items = array_merge($items, $category['items'], $this->flatItems($category['children']));
        }

        return $items;
    }

    public function test_two_templates_sharing_a_catalog_item_with_agreeing_quantities_merge_without_review(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $category = \App\Models\BoqCatalogCategory::factory()->create(['organization_id' => $organization->id]);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $category->id]);

        $versionA = $this->publishedVersion($organization);
        $versionB = $this->publishedVersion($organization);
        BoqTemplateItem::factory()->create(['template_version_id' => $versionA->id, 'catalog_item_id' => $catalogItem->id, 'category_id' => $category->id, 'default_quantity' => 10, 'is_required' => true]);
        BoqTemplateItem::factory()->create(['template_version_id' => $versionB->id, 'catalog_item_id' => $catalogItem->id, 'category_id' => $category->id, 'default_quantity' => 10, 'is_required' => true]);

        $response = $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-preview", [
            'selections' => [['template_version_id' => $versionA->id], ['template_version_id' => $versionB->id]],
        ]);

        $response->assertStatus(200);
        $items = $this->flatItems($response->json('data.categories'));
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['needs_review']);
        $this->assertSame('10.00', $items[0]['quantity']);
        $this->assertCount(2, $items[0]['contributed_by']);
    }

    public function test_a_genuine_quantity_conflict_is_flagged_needs_review_not_silently_resolved(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $category = \App\Models\BoqCatalogCategory::factory()->create(['organization_id' => $organization->id]);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $category->id]);

        $versionA = $this->publishedVersion($organization);
        $versionB = $this->publishedVersion($organization);
        BoqTemplateItem::factory()->create(['template_version_id' => $versionA->id, 'catalog_item_id' => $catalogItem->id, 'default_quantity' => 10, 'is_required' => true]);
        BoqTemplateItem::factory()->create(['template_version_id' => $versionB->id, 'catalog_item_id' => $catalogItem->id, 'default_quantity' => 25, 'is_required' => true]);

        $response = $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-preview", [
            'selections' => [['template_version_id' => $versionA->id], ['template_version_id' => $versionB->id]],
        ]);

        $response->assertStatus(200);
        $items = $this->flatItems($response->json('data.categories'));
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['needs_review']);
        $this->assertNull($items[0]['quantity']);
        $this->assertCount(2, $items[0]['contributed_by']);
    }

    public function test_required_items_are_always_included_and_optional_items_only_when_selected(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $category = \App\Models\BoqCatalogCategory::factory()->create(['organization_id' => $organization->id]);
        $requiredItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'name' => 'Required Thing']);
        $optionalItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'name' => 'Optional Thing']);
        $version = $this->publishedVersion($organization);
        BoqTemplateItem::factory()->create(['template_version_id' => $version->id, 'catalog_item_id' => $requiredItem->id, 'category_id' => $category->id, 'is_required' => true]);
        $optionalTemplateItem = BoqTemplateItem::factory()->optional()->create(['template_version_id' => $version->id, 'catalog_item_id' => $optionalItem->id, 'category_id' => $category->id]);

        $headers = $this->authHeader($user);

        $withoutOptional = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-preview", [
            'selections' => [['template_version_id' => $version->id]],
        ]);
        $names = collect($this->flatItems($withoutOptional->json('data.categories')))->pluck('name');
        $this->assertTrue($names->contains('Required Thing'));
        $this->assertFalse($names->contains('Optional Thing'));

        $withOptional = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-preview", [
            'selections' => [['template_version_id' => $version->id, 'selected_optional_item_ids' => [$optionalTemplateItem->id]]],
        ]);
        $namesWithOptional = collect($this->flatItems($withOptional->json('data.categories')))->pluck('name');
        $this->assertTrue($namesWithOptional->contains('Required Thing'));
        $this->assertTrue($namesWithOptional->contains('Optional Thing'));
    }

    public function test_preview_performs_zero_writes(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $version = $this->publishedVersion($organization);
        BoqTemplateItem::factory()->create(['template_version_id' => $version->id, 'catalog_item_id' => $catalogItem->id]);

        $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-preview", [
            'selections' => [['template_version_id' => $version->id]],
        ])->assertStatus(200);

        $this->assertDatabaseCount('boq_items', 0);
        $this->assertDatabaseCount('boq_categories', 0);
    }

    public function test_previewing_another_organizations_template_version_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->manageBoqUser($orgA);
        $projectA = $this->projectIn($orgA);
        $versionB = $this->publishedVersion($orgB);

        $this->withHeaders($this->authHeader($userA))->postJson("/api/v1/projects/{$projectA->id}/boq/template-preview", [
            'selections' => [['template_version_id' => $versionB->id]],
        ])->assertStatus(404);
    }
}
