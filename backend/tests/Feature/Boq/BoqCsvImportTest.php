<?php

namespace Tests\Feature\Boq;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CSV import (POST /projects/{project}/boq/import, App\Services\Boq\BoqCsvImporter).
 *
 * Per that class's docblock, the ACTUAL implemented transactional behavior is: the whole file
 * runs inside one DB transaction, but a row failing *validation* (missing required column,
 * non-numeric cost/quantity) does NOT abort the import — it's recorded in the response's
 * `errors` array and the loop continues, so valid rows in the same file still get created. The
 * transaction only exists to guard against a genuine unexpected DB-level failure mid-loop, not
 * to make bad *data* roll back everything. This test suite verifies that implemented behavior
 * (skip-and-continue for validation errors) rather than assuming naive "any bad row rolls back
 * everything" semantics — see the class docblock for the explicit rationale.
 */
class BoqCsvImportTest extends TestCase
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

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('boq.csv', $content);
    }

    public function test_valid_rows_create_items_and_auto_create_missing_categories_and_rooms_by_name(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $content = "category_name,room_name,name,description,quantity,unit,material_unit_cost,labor_unit_cost,other_unit_cost,client_unit_price,notes\n"
            ."Flooring,Living Room,Porcelain Tile,Grade A,10,m2,100,50,0,200,Handle with care\n"
            ."Flooring,Living Room,Skirting Board,,20,m,10,5,0,20,\n"
            ."Electrical,,Wall Socket,,4,pcs,15,10,0,40,\n";

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/boq/import", ['file' => $this->csv($content)]);

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('boq_categories', ['project_id' => $project->id, 'name' => 'Flooring']);
        $this->assertDatabaseHas('boq_categories', ['project_id' => $project->id, 'name' => 'Electrical']);
        $this->assertDatabaseHas('rooms', ['project_id' => $project->id, 'name' => 'Living Room']);
        $this->assertDatabaseHas('boq_items', ['project_id' => $project->id, 'name' => 'Porcelain Tile', 'quantity' => '10.00']);

        $wallSocket = \App\Models\BoqItem::where('project_id', $project->id)->where('name', 'Wall Socket')->first();
        $this->assertNotNull($wallSocket);
        $this->assertNull($wallSocket->room_id, 'row with empty room_name should not be attached to any room');

        // Only one Flooring category and one Living Room were created (reused across rows), not
        // duplicated per row.
        $this->assertSame(2, \App\Models\BoqCategory::where('project_id', $project->id)->count());
        $this->assertSame(1, Room::where('project_id', $project->id)->count());
    }

    public function test_malformed_rows_are_skipped_with_reasons_while_valid_rows_in_the_same_file_still_import(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $content = "category_name,room_name,name,description,quantity,unit,material_unit_cost,labor_unit_cost,other_unit_cost,client_unit_price,notes\n"
            ."Flooring,,Good Item,,10,m2,100,50,0,200,\n" // valid
            .",,Missing Category,,5,pcs,10,5,0,20,\n" // missing required category_name
            ."Flooring,,Missing Quantity,,,pcs,10,5,0,20,\n" // missing required quantity
            ."Flooring,,Bad Cost,,5,pcs,not-a-number,5,0,20,\n" // non-numeric material_unit_cost
            ."Electrical,,Second Good Item,,3,pcs,5,5,0,15,\n"; // valid

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/boq/import", ['file' => $this->csv($content)]);

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 3);

        $errors = $response->json('data.errors');
        $this->assertCount(3, $errors);

        // Row numbers are 1-indexed with the header as row 1, so data rows start at 2 —
        // matching BoqCsvImporter's documented numbering.
        $this->assertSame(3, $errors[0]['row']);
        $this->assertStringContainsString('category_name', $errors[0]['reason']);
        $this->assertSame(4, $errors[1]['row']);
        $this->assertStringContainsString('quantity', $errors[1]['reason']);
        $this->assertSame(5, $errors[2]['row']);
        $this->assertStringContainsString('material_unit_cost', $errors[2]['reason']);

        // The valid rows committed despite the skipped rows in the same file — the import is
        // "skip-and-continue" for row-level validation problems, not all-or-nothing.
        $this->assertDatabaseHas('boq_items', ['project_id' => $project->id, 'name' => 'Good Item']);
        $this->assertDatabaseHas('boq_items', ['project_id' => $project->id, 'name' => 'Second Good Item']);
        $this->assertDatabaseMissing('boq_items', ['project_id' => $project->id, 'name' => 'Missing Category']);
        $this->assertDatabaseMissing('boq_items', ['project_id' => $project->id, 'name' => 'Missing Quantity']);
        $this->assertDatabaseMissing('boq_items', ['project_id' => $project->id, 'name' => 'Bad Cost']);
    }

    public function test_blank_lines_are_silently_ignored_not_counted_as_errors(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $content = "category_name,room_name,name,description,quantity,unit,material_unit_cost,labor_unit_cost,other_unit_cost,client_unit_price,notes\n"
            ."Flooring,,Good Item,,10,m2,100,50,0,200,\n"
            ."\n"
            ."Flooring,,Another Item,,5,m2,50,25,0,100,\n";

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/boq/import", ['file' => $this->csv($content)]);

        $response->assertStatus(200)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 0);
    }

    public function test_import_requires_manage_boq_permission(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, []);

        $content = "category_name,name,quantity,unit\nFlooring,Tile,1,m2\n";

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/boq/import", ['file' => $this->csv($content)])
            ->assertStatus(403);

        $this->assertDatabaseMissing('boq_items', ['name' => 'Tile']);
    }

    public function test_import_is_tenant_isolated(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_BOQ => true]);
        $projectB = $this->projectIn($orgB);

        $content = "category_name,name,quantity,unit\nFlooring,Tile,1,m2\n";

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/boq/import", ['file' => $this->csv($content)])
            ->assertStatus(404);

        $this->assertDatabaseMissing('boq_items', ['name' => 'Tile']);
    }
}
