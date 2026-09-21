<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\BoqTemplateCategory;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * RBAC coverage for Sprint 2's BOQ surface: every BOQ *write* endpoint requires
 * Permissions::MANAGE_BOQ (per StoreBoqCategoryRequest/StoreBoqItemRequest/UpdateBoqItemRequest/
 * StoreRoomRequest/ImportBoqRequest/StoreBoqTemplateCategoryRequest/StoreBoqTemplateItemRequest
 * docblocks, plus Gate::authorize() calls in BoqItemController::destroy() and
 * BoqTemplateController::apply()), while every BOQ *read* endpoint only requires an active
 * organization membership — matching database/seeders/RoleSeeder.php, where the seeded
 * "Designer" role has manage_boq => true (they own the BOQ Builder day to day) but a member
 * with no permissions at all (e.g. the seeded "Site Staff" role, or any custom read-only role)
 * has manage_boq => false and must be able to view without being able to mutate.
 */
class BoqRbacTest extends TestCase
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

    public function test_read_only_member_can_view_the_boq_but_not_mutate_it(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        $item = BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);

        // No manage_boq permission at all — matches the seeded "Site Staff" role's permission
        // set (database/seeders/RoleSeeder.php: array_fill_keys(Permissions::ALL, false)).
        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        // --- Reads succeed ---
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq")->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/rooms")->assertStatus(200);
        $this->withHeaders($headers)->getJson('/api/v1/boq-templates/categories')->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq/export")->assertStatus(200);

        // --- Writes are forbidden ---
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/categories", ['name' => 'Should Fail'])
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->assertDatabaseMissing('boq_categories', ['name' => 'Should Fail']);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/items", [
                'category_id' => $category->id, 'name' => 'Should Fail', 'quantity' => 1, 'unit' => 'pcs',
            ])
            ->assertStatus(403);
        $this->assertDatabaseMissing('boq_items', ['name' => 'Should Fail']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/boq/items/{$item->id}", ['name' => 'Should Fail'])
            ->assertStatus(403);
        $this->assertNotSame('Should Fail', $item->fresh()->name);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/boq/items/{$item->id}")
            ->assertStatus(403);
        $this->assertNull($item->fresh()->archived_at);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/rooms", ['name' => 'Should Fail'])
            ->assertStatus(403);
        $this->assertDatabaseMissing('rooms', ['name' => 'Should Fail']);

        $templateCategory = BoqTemplateCategory::factory()->create(['organization_id' => $organization->id]);
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/apply-template/{$templateCategory->id}")
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->postJson('/api/v1/boq-templates/categories', ['name' => 'Should Fail'])
            ->assertStatus(403);
        $this->assertDatabaseMissing('boq_template_categories', ['name' => 'Should Fail']);

        $this->withHeaders($headers)
            ->postJson("/api/v1/boq-templates/categories/{$templateCategory->id}/items", ['name' => 'Should Fail', 'unit' => 'pcs'])
            ->assertStatus(403);
        $this->assertDatabaseMissing('boq_template_items', ['name' => 'Should Fail']);

        $csv = UploadedFile::fake()->createWithContent('boq.csv', "category_name,name,quantity,unit\nFlooring,Tile,1,m2\n");
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/import", ['file' => $csv])
            ->assertStatus(403);
        $this->assertDatabaseMissing('boq_items', ['name' => 'Tile']);
    }

    public function test_a_member_with_manage_boq_can_perform_every_write_operation(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/categories", ['name' => 'Flooring'])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/rooms", ['name' => 'Living Room'])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson('/api/v1/boq-templates/categories', ['name' => 'Office Template'])
            ->assertStatus(201);
    }
}
