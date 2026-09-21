<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\BoqTemplateCategory;
use App\Models\BoqTemplateItem;
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
 * "Apply template to project" (PROJECT_CONTEXT.md Sprint 2 "Templates" / App\Services\Boq\
 * BoqTemplateCloner): applying a template must COPY rows into the project's boq_categories/
 * boq_items as brand new ids, never reference the template — editing the cloned project BOQ
 * must never mutate the master template. This test proves both directions: the clone gets new
 * ids distinct from the template's, and mutating the cloned project item afterward leaves the
 * template item completely unaffected.
 */
class BoqTemplateCloneTest extends TestCase
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

    public function test_applying_a_template_clones_new_rows_and_never_mutates_the_template(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        // Build a two-level template tree: root category with one item, one nested child
        // category with its own item.
        $rootTemplateCategory = BoqTemplateCategory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Flooring Template',
        ]);
        $childTemplateCategory = BoqTemplateCategory::factory()->create([
            'organization_id' => $organization->id,
            'parent_id' => $rootTemplateCategory->id,
            'name' => 'Tiling Template',
        ]);
        $rootTemplateItem = BoqTemplateItem::factory()->create([
            'organization_id' => $organization->id,
            'category_id' => $rootTemplateCategory->id,
            'name' => 'Template Root Item',
            'material_unit_cost' => 100,
            'labor_unit_cost' => 50,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ]);
        $childTemplateItem = BoqTemplateItem::factory()->create([
            'organization_id' => $organization->id,
            'category_id' => $childTemplateCategory->id,
            'name' => 'Template Child Item',
            'material_unit_cost' => 40,
            'labor_unit_cost' => 10,
            'other_unit_cost' => 0,
            'client_unit_price' => 80,
        ]);

        $apply = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/apply-template/{$rootTemplateCategory->id}");
        $apply->assertStatus(201);

        $newRootCategoryId = $apply->json('data.applied_category_id');

        // --- New rows were created in the project's own tables, not references back to the
        // template's rows. Template categories/items live in an entirely separate table
        // (boq_template_categories/boq_template_items) from a project's boq_categories/
        // boq_items, so id equality/inequality across those two tables isn't itself meaningful
        // (both sequences independently start at 1) — the real proof of "copy, not reference"
        // is that the template's own row counts are completely unaffected by the clone, while
        // the project gains brand-new boq_categories/boq_items rows carrying the same content.
        $this->assertSame(2, \App\Models\BoqTemplateCategory::where('organization_id', $organization->id)->count());
        $this->assertSame(2, \App\Models\BoqTemplateItem::where('organization_id', $organization->id)->count());

        $clonedRootCategory = BoqCategory::find($newRootCategoryId);
        $this->assertNotNull($clonedRootCategory);
        $this->assertSame($project->id, $clonedRootCategory->project_id);
        $this->assertSame('Flooring Template', $clonedRootCategory->name);

        $clonedChildCategory = BoqCategory::where('project_id', $project->id)
            ->where('parent_id', $newRootCategoryId)
            ->where('name', 'Tiling Template')
            ->first();
        $this->assertNotNull($clonedChildCategory, 'expected the nested template category to be cloned too');
        $this->assertSame(2, BoqCategory::where('project_id', $project->id)->count());

        $clonedRootItem = BoqItem::where('project_id', $project->id)
            ->where('category_id', $newRootCategoryId)
            ->where('name', 'Template Root Item')
            ->first();
        $clonedChildItem = BoqItem::where('project_id', $project->id)
            ->where('category_id', $clonedChildCategory->id)
            ->where('name', 'Template Child Item')
            ->first();
        $this->assertNotNull($clonedRootItem);
        $this->assertNotNull($clonedChildItem);
        $this->assertSame(2, BoqItem::where('project_id', $project->id)->count());

        // Cloned item copies the template's cost/price fields verbatim, but per
        // BoqTemplateCloner's docblock it deliberately leaves quantity at its column default
        // (0) — a template prices a unit rate, not a project-specific measured quantity, so
        // the designer fills quantity in once the item lives in the real project BOQ. That
        // means client_total (client_unit_price * quantity) is 0.00 immediately after cloning
        // even though client_unit_price itself was copied.
        $this->assertSame('100.00', $clonedRootItem->material_unit_cost);
        $this->assertSame('200.00', $clonedRootItem->client_unit_price);
        $this->assertSame('0.00', (string) $clonedRootItem->quantity);
        $this->assertSame('0.00', $clonedRootItem->client_total);

        // --- Mutating the cloned project item never touches the template ---
        $mutate = $this->withHeaders($headers)->patchJson("/api/v1/boq/items/{$clonedRootItem->id}", [
            'name' => 'Customized For This Project',
            'quantity' => 5,
            'material_unit_cost' => 999,
        ]);
        $mutate->assertStatus(200);

        $rootTemplateItem->refresh();
        $this->assertSame('Template Root Item', $rootTemplateItem->name);
        $this->assertSame('100.00', $rootTemplateItem->material_unit_cost);

        // Archiving the cloned item must not affect the template category/item either.
        $this->withHeaders($headers)->deleteJson("/api/v1/boq/items/{$clonedRootItem->id}")->assertStatus(200);
        $this->assertNotNull(BoqTemplateItem::find($rootTemplateItem->id));

        // The master template tree is completely unchanged in shape and values.
        $templateTree = $this->withHeaders($headers)->getJson('/api/v1/boq-templates/categories')->assertStatus(200);
        $rootNode = collect($templateTree->json('data.categories'))->firstWhere('id', $rootTemplateCategory->id);
        $this->assertSame('Flooring Template', $rootNode['name']);
        $this->assertCount(1, $rootNode['items']);
        $this->assertSame('Template Root Item', $rootNode['items'][0]['name']);
        $this->assertSame('100.00', $rootNode['items'][0]['material_unit_cost']);
    }

    public function test_applying_the_same_template_twice_produces_two_independent_clones(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $templateCategory = BoqTemplateCategory::factory()->create(['organization_id' => $organization->id]);
        BoqTemplateItem::factory()->create(['organization_id' => $organization->id, 'category_id' => $templateCategory->id]);

        $firstApply = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/apply-template/{$templateCategory->id}")
            ->assertStatus(201);
        $secondApply = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/apply-template/{$templateCategory->id}")
            ->assertStatus(201);

        $this->assertNotEquals(
            $firstApply->json('data.applied_category_id'),
            $secondApply->json('data.applied_category_id'),
        );
        $this->assertSame(2, BoqCategory::where('project_id', $project->id)->count());
    }
}
