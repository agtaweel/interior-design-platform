<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCategory;
use App\Models\BoqItem;
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
 * CSV export (GET /projects/{project}/boq/export, App\Services\Boq\BoqCsvExporter) — verifies
 * the streamed CSV content matches the project's current non-archived BOQ state exactly, using
 * the same column order as BoqCsvExporter::HEADER (kept in sync with BoqCsvImporter so a
 * downloaded file round-trips back through import unchanged, per that class's docblock).
 */
class BoqCsvExportTest extends TestCase
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

    public function test_export_matches_current_non_archived_boq_state(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $category = BoqCategory::factory()->create(['project_id' => $project->id, 'name' => 'Flooring']);
        $room = Room::factory()->create(['project_id' => $project->id, 'name' => 'Living Room']);

        $activeItem = BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'room_id' => $room->id,
            'name' => 'Porcelain Tile',
            'description' => 'Grade A',
            'quantity' => 10,
            'unit' => 'm2',
            'material_unit_cost' => 100,
            'labor_unit_cost' => 50,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
            'notes' => 'Handle with care',
            'archived_at' => null,
        ]);

        // Archived items must never appear in the export.
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'name' => 'Old Discontinued Item',
            'archived_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeader($user))->get("/api/v1/projects/{$project->id}/boq/export");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $csv = $this->streamedContent($response);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame(
            ['category_name', 'room_name', 'name', 'description', 'quantity', 'unit', 'material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'client_unit_price', 'notes'],
            $rows[0],
        );

        // Only the one non-archived item is present.
        $this->assertCount(2, $rows); // header + 1 data row
        $dataRow = array_combine($rows[0], $rows[1]);

        $this->assertSame('Flooring', $dataRow['category_name']);
        $this->assertSame('Living Room', $dataRow['room_name']);
        $this->assertSame('Porcelain Tile', $dataRow['name']);
        $this->assertSame('Grade A', $dataRow['description']);
        $this->assertSame((string) $activeItem->quantity, $dataRow['quantity']);
        $this->assertSame('m2', $dataRow['unit']);
        $this->assertSame((string) $activeItem->material_unit_cost, $dataRow['material_unit_cost']);
        $this->assertSame((string) $activeItem->labor_unit_cost, $dataRow['labor_unit_cost']);
        $this->assertSame((string) $activeItem->client_unit_price, $dataRow['client_unit_price']);
        $this->assertSame('Handle with care', $dataRow['notes']);

        $this->assertStringNotContainsString('Old Discontinued Item', $csv);
    }

    public function test_export_requires_only_an_active_membership_not_manage_boq(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $readOnlyUser = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($readOnlyUser))
            ->get("/api/v1/projects/{$project->id}/boq/export")
            ->assertStatus(200);
    }

    public function test_export_is_tenant_isolated(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_BOQ => true]);
        $projectB = $this->projectIn($orgB);

        $this->withHeaders($this->authHeader($userA))
            ->get("/api/v1/projects/{$projectB->id}/boq/export")
            ->assertStatus(404);
    }

    /**
     * StreamedResponse bodies aren't captured by TestResponse::getContent() by default — drain
     * the stream callback into a string instead, same technique Laravel's own streamed-response
     * test helpers use.
     */
    private function streamedContent(\Illuminate\Testing\TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return ob_get_clean();
    }
}
