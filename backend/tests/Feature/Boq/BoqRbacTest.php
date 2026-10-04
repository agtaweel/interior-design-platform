<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCategory;
use App\Models\BoqItem;
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
 * RBAC coverage for Sprint 2's BOQ surface, updated for Sprint 8's "Permissions hardening"
 * (PROJECT_CONTEXT.md): every BOQ *write* endpoint requires Permissions::MANAGE_BOQ (per
 * StoreBoqCategoryRequest/StoreBoqItemRequest/UpdateBoqItemRequest/StoreRoomRequest/
 * ImportBoqRequest docblocks, plus the Gate::authorize() call in BoqItemController::destroy())
 * — and, as of Sprint 8, so does GET /projects/{id}/boq, GET /projects/{id}/boq/export,
 * GET /projects/{id}/rooms, since their responses include material_unit_cost/labor_unit_cost/
 * other_unit_cost (see IndexBoqRequest/BoqController/RoomController docblocks).
 *
 * BOQ Master Catalog + Standard Templates RBAC (boq-catalog/*, boq-templates/*,
 * template-preview/template-commit) is covered separately — see BoqCatalogTreeTest.php and
 * BoqTemplateVersioningTest.php.
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

    public function test_read_only_member_is_forbidden_from_every_cost_bearing_boq_read_and_every_write(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        $item = BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);

        // No manage_boq permission at all — matches the seeded "Site Staff" role's permission
        // set (database/seeders/RoleSeeder.php: array_fill_keys(Permissions::ALL, false)).
        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        // --- Cost-bearing BOQ reads are forbidden (Sprint 8 hardening) ---
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq")->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/rooms")->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq/export")->assertStatus(403);

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

        // BOQ Master Catalog + Standard Templates RBAC (boq-catalog/*, boq-templates/*,
        // template-preview/template-commit) is covered in BoqCatalogTreeTest.php and
        // BoqTemplateVersioningTest.php rather than duplicated here.

        $csv = UploadedFile::fake()->createWithContent('boq.csv', "category_name,name,quantity,unit\nFlooring,Tile,1,m2\n");
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/boq/import", ['file' => $csv])
            ->assertStatus(403);
        $this->assertDatabaseMissing('boq_items', ['name' => 'Tile']);
    }

    public function test_a_member_with_manage_boq_can_perform_every_write_operation_and_every_read(): void
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

        // Sprint 8 hardening: a MANAGE_BOQ holder (Designer/Admin/Owner) remains unaffected on
        // every read this sprint hardened.
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq")->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/rooms")->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq/export")->assertStatus(200);
    }
}
