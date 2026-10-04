<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\BoqTemplate;
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
 * BOQ Master Catalog + Standard Templates — BoqTemplateCommitService, replacing the deleted
 * BoqTemplateCloneTest.php (the old BoqTemplateCloner it tested no longer exists). Focused on
 * project-category assembly from the catalog's category tree and on the "committed BOQ item is
 * fully independent of its source" guarantee — source-column stamping itself and the
 * one-application-per-version bookkeeping are covered in BoqTemplateApplicationLoggingTest.php.
 */
class BoqTemplateCommitTest extends TestCase
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

    public function test_commit_creates_a_nested_project_category_tree_matching_the_catalog_categorys_parent_chain(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);

        $parentCategory = BoqCatalogCategory::factory()->create(['organization_id' => $organization->id, 'name' => 'Kitchen']);
        $childCategory = BoqCatalogCategory::factory()->create(['organization_id' => $organization->id, 'parent_id' => $parentCategory->id, 'name' => 'Cabinets']);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $childCategory->id]);

        $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [['catalog_item_id' => $catalogItem->id, 'category_id' => $childCategory->id, 'quantity' => 1, 'unit' => 'pcs']],
        ])->assertStatus(201);

        $projectParent = BoqCategory::where('project_id', $project->id)->where('name', 'Kitchen')->whereNull('parent_id')->first();
        $projectChild = BoqCategory::where('project_id', $project->id)->where('name', 'Cabinets')->first();

        $this->assertNotNull($projectParent);
        $this->assertNotNull($projectChild);
        $this->assertSame($projectParent->id, $projectChild->parent_id);
    }

    public function test_applying_a_second_template_that_shares_a_category_reuses_the_existing_project_category(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $category = BoqCatalogCategory::factory()->create(['organization_id' => $organization->id, 'name' => 'Flooring']);
        $itemOne = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $category->id]);
        $itemTwo = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $category->id]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [['catalog_item_id' => $itemOne->id, 'category_id' => $category->id, 'quantity' => 1, 'unit' => 'pcs']],
        ])->assertStatus(201);

        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [['catalog_item_id' => $itemTwo->id, 'category_id' => $category->id, 'quantity' => 1, 'unit' => 'pcs']],
        ])->assertStatus(201);

        $this->assertSame(1, BoqCategory::where('project_id', $project->id)->where('name', 'Flooring')->count());
    }

    public function test_editing_the_committed_item_never_touches_the_catalog_item_or_template_item(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $catalogItem = BoqCatalogItem::factory()->create([
            'organization_id' => $organization->id,
            'default_client_unit_price' => 1500,
        ]);
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $version = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id]);
        $templateItem = \App\Models\BoqTemplateItem::factory()->create([
            'template_version_id' => $version->id, 'catalog_item_id' => $catalogItem->id, 'default_quantity' => 5,
        ]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [[
                'catalog_item_id' => $catalogItem->id, 'quantity' => 5, 'unit' => 'pcs',
                'source_template_id' => $template->id, 'source_template_version_id' => $version->id, 'source_template_item_id' => $templateItem->id,
            ]],
        ])->assertStatus(201);

        $boqItem = BoqItem::where('project_id', $project->id)->firstOrFail();
        $this->withHeaders($headers)->patchJson("/api/v1/boq/items/{$boqItem->id}", ['client_unit_price' => 99999])->assertStatus(200);

        $this->assertSame('1500.00', (string) $catalogItem->fresh()->default_client_unit_price);
        $this->assertSame('5.00', (string) $templateItem->fresh()->default_quantity);
    }

    public function test_commit_rejects_an_item_with_no_resolvable_unit(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id, 'default_unit_id' => null]);

        $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [['catalog_item_id' => $catalogItem->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('boq_items', 0);
    }

    public function test_commit_requires_manage_boq_permission(): void
    {
        $organization = Organization::factory()->create();
        $readOnlyRole = Role::factory()->create(['organization_id' => null, 'permissions_json' => []]);
        $readOnlyUser = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id, 'user_id' => $readOnlyUser->id, 'role_id' => $readOnlyRole->id, 'status' => 'active',
        ]);
        $project = $this->projectIn($organization);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($readOnlyUser))->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [['catalog_item_id' => $catalogItem->id, 'quantity' => 1, 'unit' => 'pcs']],
        ])->assertStatus(403);
    }

    public function test_cannot_commit_a_foreign_organizations_template_version_into_ones_own_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->manageBoqUser($orgA);
        $projectA = $this->projectIn($orgA);
        $catalogItemB = BoqCatalogItem::factory()->create(['organization_id' => $orgB->id]);
        $templateB = BoqTemplate::factory()->create(['organization_id' => $orgB->id]);
        $versionB = BoqTemplateVersion::factory()->published()->create(['template_id' => $templateB->id]);

        $this->withHeaders($this->authHeader($userA))->postJson("/api/v1/projects/{$projectA->id}/boq/template-commit", [
            'items' => [[
                'catalog_item_id' => $catalogItemB->id, 'quantity' => 1, 'unit' => 'pcs',
                'source_template_id' => $templateB->id, 'source_template_version_id' => $versionB->id,
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('boq_items', 0);
    }

    public function test_cannot_commit_into_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->manageBoqUser($orgA);
        $projectB = $this->projectIn($orgB);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $orgA->id]);

        $this->withHeaders($this->authHeader($userA))->postJson("/api/v1/projects/{$projectB->id}/boq/template-commit", [
            'items' => [['catalog_item_id' => $catalogItem->id, 'quantity' => 1, 'unit' => 'pcs']],
        ])->assertStatus(404);
    }
}
