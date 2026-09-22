<?php

namespace Tests\Feature\Pricing;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PricingRule;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /projects/{project}/pricing/breakdown — per PricingController's docblock: returns an
 * explicit "not yet priced" shape (priced: false, every total null) before the project has ever
 * been recalculated, and a full live line-by-line breakdown after at least one recalculate.
 */
class PricingBreakdownTest extends TestCase
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

    private function seedKnownBoq(Project $project): void
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'quantity' => 10,
            'material_unit_cost' => 100,
            'labor_unit_cost' => 0,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ]);
    }

    public function test_breakdown_reports_not_priced_before_any_recalculation(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project);
        PricingRule::factory()->create(['project_id' => $project->id]); // rules can exist unpriced

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/pricing/breakdown")
            ->assertStatus(200);

        $response->assertJsonPath('data.priced', false)
            ->assertJsonPath('data.direct_cost_total', null)
            ->assertJsonPath('data.client_subtotal', null)
            ->assertJsonPath('data.rules', [])
            ->assertJsonPath('data.markup_total', null)
            ->assertJsonPath('data.fees_total', null)
            ->assertJsonPath('data.discount_total', null)
            ->assertJsonPath('data.grand_total', null)
            ->assertJsonPath('data.priced_at', null);
    }

    public function test_breakdown_returns_the_full_accurate_breakdown_after_recalculation(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // direct_cost_total=1000.00, client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Contractor Markup', 'type' => 'markup',
            'method' => 'percentage', 'value' => 15, 'base_selector' => 'boq_direct_cost',
            'sort_order' => 1,
        ]);
        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Design Fee', 'type' => 'fee',
            'method' => 'percentage', 'value' => 12, 'base_selector' => 'boq_client_subtotal',
            'sort_order' => 2,
        ]);

        $headers = $this->authHeader($user);
        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/pricing/recalculate")
            ->assertStatus(200);

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/pricing/breakdown")
            ->assertStatus(200);

        // 15% of 1000.00 = 150.00; 12% of 2000.00 = 240.00; grand = 2000+150+240 = 2390.00
        $response->assertJsonPath('data.priced', true)
            ->assertJsonPath('data.direct_cost_total', '1000.00')
            ->assertJsonPath('data.client_subtotal', '2000.00')
            ->assertJsonPath('data.markup_total', '150.00')
            ->assertJsonPath('data.fees_total', '240.00')
            ->assertJsonPath('data.discount_total', '0.00')
            ->assertJsonPath('data.grand_total', '2390.00')
            ->assertJsonCount(2, 'data.rules');

        $this->assertNotNull($response->json('data.priced_at'));
        $this->assertSame('Contractor Markup', $response->json('data.rules.0.name'));
        $this->assertSame('150.00', $response->json('data.rules.0.computed_amount'));
        $this->assertSame('2150.00', $response->json('data.rules.0.running_subtotal_after'));
        $this->assertSame('Design Fee', $response->json('data.rules.1.name'));
        $this->assertSame('240.00', $response->json('data.rules.1.computed_amount'));
        $this->assertSame('2390.00', $response->json('data.rules.1.running_subtotal_after'));
    }

    public function test_breakdown_for_a_nonexistent_project_returns_not_found(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/projects/999999/pricing/breakdown')
            ->assertStatus(404);
    }
}
