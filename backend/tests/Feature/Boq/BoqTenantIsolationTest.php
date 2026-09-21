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
use App\Models\Room;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Extends the tenant-isolation guarantee proven generically in
 * tests/Feature/Tenancy/TenantIsolationTest.php to Sprint 2's BOQ surface: a user authenticated
 * into organization A must never be able to read or write organization B's rooms, BOQ
 * categories, BOQ items, or organization-level BOQ templates, even by guessing/enumerating ids.
 */
class BoqTenantIsolationTest extends TestCase
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

    // --- Rooms ---

    public function test_cannot_list_or_create_rooms_for_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        Room::factory()->create(['project_id' => $projectB->id]);

        $headersA = $this->authHeader($userA);

        $this->withHeaders($headersA)->getJson("/api/v1/projects/{$projectB->id}/rooms")->assertStatus(404);

        $create = $this->withHeaders($headersA)->postJson("/api/v1/projects/{$projectB->id}/rooms", ['name' => 'Intruder Room']);
        $create->assertStatus(404);
        $this->assertDatabaseMissing('rooms', ['name' => 'Intruder Room']);
    }

    // --- Categories ---

    public function test_cannot_create_a_boq_category_for_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);

        $response = $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/boq/categories", ['name' => 'Intruder Category']);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('boq_categories', ['name' => 'Intruder Category']);
    }

    // --- Items ---

    public function test_cannot_view_another_organizations_boq_via_the_index_endpoint(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $categoryB = BoqCategory::factory()->create(['project_id' => $projectB->id]);
        BoqItem::factory()->create(['project_id' => $projectB->id, 'category_id' => $categoryB->id]);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectB->id}/boq")
            ->assertStatus(404);
    }

    public function test_cannot_create_a_boq_item_for_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $categoryB = BoqCategory::factory()->create(['project_id' => $projectB->id]);

        $response = $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/boq/items", [
                'category_id' => $categoryB->id,
                'name' => 'Intruder Item',
                'quantity' => 1,
                'unit' => 'pcs',
            ]);

        // The route project ({project}=projectB) resolves to null under org A's tenant scope,
        // so category_id's project-scoped exists-check (scoped to null) never matches — this
        // surfaces as a 422 validation failure rather than the controller's 404 branch, same
        // documented behavior as AddProjectMemberRequest in ProjectMemberAndServiceTest.
        $this->assertContains($response->status(), [404, 422]);
        $this->assertDatabaseMissing('boq_items', ['name' => 'Intruder Item']);
    }

    public function test_cannot_update_or_archive_another_organizations_boq_item_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $categoryB = BoqCategory::factory()->create(['project_id' => $projectB->id]);
        $itemB = BoqItem::factory()->create([
            'project_id' => $projectB->id,
            'category_id' => $categoryB->id,
            'name' => 'Original Name',
        ]);

        $headersA = $this->authHeader($userA);

        $this->withHeaders($headersA)
            ->patchJson("/api/v1/boq/items/{$itemB->id}", ['name' => 'Hacked Name'])
            ->assertStatus(404);
        $this->assertSame('Original Name', $itemB->fresh()->name);

        $this->withHeaders($headersA)
            ->deleteJson("/api/v1/boq/items/{$itemB->id}")
            ->assertStatus(404);
        $this->assertNull($itemB->fresh()->archived_at);
    }

    // --- Organization-level templates ---

    public function test_cannot_list_or_create_another_organizations_boq_templates(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $this->fullAccessUser($orgB);
        $templateCategoryB = BoqTemplateCategory::factory()->create(['organization_id' => $orgB->id, 'name' => 'Org B Template']);
        BoqTemplateItem::factory()->create(['organization_id' => $orgB->id, 'category_id' => $templateCategoryB->id]);

        $headersA = $this->authHeader($userA);

        // Index is scoped to the current tenant context, so org A never sees org B's templates.
        $list = $this->withHeaders($headersA)->getJson('/api/v1/boq-templates/categories');
        $list->assertStatus(200)->assertJsonMissing(['name' => 'Org B Template']);

        // Cannot add a template item to org B's category by guessing its id.
        $this->withHeaders($headersA)
            ->postJson("/api/v1/boq-templates/categories/{$templateCategoryB->id}/items", [
                'name' => 'Intruder Template Item',
                'unit' => 'pcs',
            ])
            ->assertStatus(404);
        $this->assertDatabaseMissing('boq_template_items', ['name' => 'Intruder Template Item']);
    }

    public function test_cannot_apply_another_organizations_template_to_a_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectA = $this->projectIn($orgA);
        $templateCategoryB = BoqTemplateCategory::factory()->create(['organization_id' => $orgB->id]);
        BoqTemplateItem::factory()->create(['organization_id' => $orgB->id, 'category_id' => $templateCategoryB->id]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectA->id}/boq/apply-template/{$templateCategoryB->id}")
            ->assertStatus(404);

        $this->assertDatabaseMissing('boq_categories', ['project_id' => $projectA->id]);
    }

    public function test_cannot_apply_a_template_to_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $templateCategoryA = BoqTemplateCategory::factory()->create(['organization_id' => $orgA->id]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/boq/apply-template/{$templateCategoryA->id}")
            ->assertStatus(404);
    }
}
