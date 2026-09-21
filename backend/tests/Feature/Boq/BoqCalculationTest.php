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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifies PROJECT_CONTEXT.md Sprint 2's documented per-item formulas exactly:
 *   direct_cost   = (material_unit_cost + labor_unit_cost + other_unit_cost) * quantity
 *   client_total  = client_unit_price * quantity
 * and that category/room subtotals and the project grand total roll up correctly.
 *
 * Also verifies the *actual implemented* scope decision for nested-category subtotals
 * (App\Services\Boq\BoqTreeService's docblock): a parent category's subtotal only sums items
 * directly assigned to it (category_id = parent.id), NOT items belonging to its child
 * categories — each child shows its own subtotal instead, and the project grand_total sums
 * every non-archived item regardless of nesting so nothing is hidden. PROJECT_CONTEXT.md itself
 * doesn't explicitly settle whether parent subtotals should be children-inclusive, so this test
 * documents/pins the implementer's resolution rather than assuming an interpretation.
 */
class BoqCalculationTest extends TestCase
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

    /**
     * @return array<int, array{material: string, labor: string, other: string, price: string, qty: string, direct: string, total: string}>
     */
    public static function costCombinations(): array
    {
        return [
            'whole numbers' => ['100.00', '50.00', '0.00', '200.00', '10.00', '1500.00', '2000.00'],
            'two-decimal costs and quantity' => ['100.50', '50.25', '10.10', '200.75', '3.00', '482.55', '602.25'],
            'fractional quantity' => ['80.00', '20.00', '5.00', '150.00', '2.50', '262.50', '375.00'],
            'zero costs' => ['0.00', '0.00', '0.00', '0.00', '5.00', '0.00', '0.00'],
            'large values' => ['999.99', '499.99', '99.99', '1999.99', '12.00', '19199.64', '23999.88'],
        ];
    }

    #[DataProvider('costCombinations')]
    public function test_direct_cost_and_client_total_match_the_documented_formulas(
        string $material,
        string $labor,
        string $other,
        string $price,
        string $qty,
        string $expectedDirect,
        string $expectedTotal,
    ): void {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $response = $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/items", [
            'category_id' => $category->id,
            'name' => 'Test line item',
            'quantity' => $qty,
            'unit' => 'pcs',
            'material_unit_cost' => $material,
            'labor_unit_cost' => $labor,
            'other_unit_cost' => $other,
            'client_unit_price' => $price,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.direct_cost', $expectedDirect)
            ->assertJsonPath('data.client_total', $expectedTotal);

        // Cross-check directly against the model's bcmath accessors too, not just the HTTP
        // response, in case the resource ever diverges from the model.
        $item = BoqItem::find($response->json('data.id'));
        $this->assertSame($expectedDirect, $item->direct_cost);
        $this->assertSame($expectedTotal, $item->client_total);
    }

    public function test_category_room_and_grand_total_subtotals_roll_up_correctly_without_double_counting_nested_categories(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $parent = BoqCategory::factory()->create(['project_id' => $project->id, 'name' => 'Flooring']);
        $child = BoqCategory::factory()->create(['project_id' => $project->id, 'parent_id' => $parent->id, 'name' => 'Tiling']);
        $room = Room::factory()->create(['project_id' => $project->id]);

        // Item directly on the parent category, in the room.
        $parentItem = BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $parent->id,
            'room_id' => $room->id,
            'quantity' => 10,
            'material_unit_cost' => 10, 'labor_unit_cost' => 5, 'other_unit_cost' => 0,
            'client_unit_price' => 20,
        ]);
        // direct_cost = 150.00, client_total = 200.00

        // Item on the nested child category, NOT in the room.
        $childItem = BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $child->id,
            'room_id' => null,
            'quantity' => 4,
            'material_unit_cost' => 25, 'labor_unit_cost' => 25, 'other_unit_cost' => 0,
            'client_unit_price' => 100,
        ]);
        // direct_cost = 200.00, client_total = 400.00

        // An archived item that must never contribute to any subtotal by default.
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $parent->id,
            'quantity' => 999,
            'material_unit_cost' => 999, 'labor_unit_cost' => 0, 'other_unit_cost' => 0,
            'client_unit_price' => 999,
            'archived_at' => now(),
        ]);

        $tree = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/boq")->assertStatus(200);

        $parentNode = collect($tree->json('data.categories'))->firstWhere('id', $parent->id);
        $childNode = collect($parentNode['children'])->firstWhere('id', $child->id);

        // Parent subtotal = only the item directly assigned to it (150.00/200.00), NOT the
        // child category's item (200.00/400.00) — this is the implemented scope decision.
        $this->assertSame('150.00', $parentNode['subtotal']['direct_cost']);
        $this->assertSame('200.00', $parentNode['subtotal']['client_total']);
        $this->assertCount(1, $parentNode['items']);

        // Child subtotal = only its own item.
        $this->assertSame('200.00', $childNode['subtotal']['direct_cost']);
        $this->assertSame('400.00', $childNode['subtotal']['client_total']);

        // Room subtotal = only the item assigned to that room (the parent-category item).
        $roomNode = collect($tree->json('data.rooms'))->firstWhere('id', $room->id);
        $this->assertSame('150.00', $roomNode['subtotal']['direct_cost']);
        $this->assertSame('200.00', $roomNode['subtotal']['client_total']);

        // Grand total = sum of BOTH non-archived items (350.00/600.00), regardless of nesting —
        // nothing is hidden even though it isn't double-counted per-category.
        $this->assertSame('350.00', $tree->json('data.grand_total.direct_cost'));
        $this->assertSame('600.00', $tree->json('data.grand_total.client_total'));
    }
}
