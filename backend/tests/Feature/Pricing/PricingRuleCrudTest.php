<?php

namespace Tests\Feature\Pricing;

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
 * CRUD + validation coverage for pricing_rules, per docs/PROJECT_CONTEXT.md Sprint 3 scope:
 * GET/POST /projects/{project}/pricing/rules, PATCH/DELETE /pricing/rules/{rule}.
 * Enum-style columns (type, method, base_selector) are plain strings validated at the app layer
 * via Rule::in() in Store/UpdatePricingRuleRequest — this test proves invalid values are
 * actually rejected, not merely documented.
 */
class PricingRuleCrudTest extends TestCase
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

    public function test_can_create_a_pricing_rule_and_it_persists_all_fields(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Design Fee',
                'type' => 'fee',
                'method' => 'percentage',
                'value' => 12.5,
                'base_selector' => 'boq_client_subtotal',
                'sort_order' => 3,
                'active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Design Fee')
            ->assertJsonPath('data.type', 'fee')
            ->assertJsonPath('data.method', 'percentage')
            ->assertJsonPath('data.value', '12.50')
            ->assertJsonPath('data.base_selector', 'boq_client_subtotal')
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.project_id', $project->id);

        $this->assertDatabaseHas('pricing_rules', [
            'project_id' => $project->id,
            'name' => 'Design Fee',
            'type' => 'fee',
            'method' => 'percentage',
            'base_selector' => 'boq_client_subtotal',
            'sort_order' => 3,
            'active' => true,
        ]);
    }

    public function test_can_list_pricing_rules_for_a_project_ordered_by_sort_order(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $third = PricingRule::factory()->create(['project_id' => $project->id, 'name' => 'Third', 'sort_order' => 30]);
        $first = PricingRule::factory()->create(['project_id' => $project->id, 'name' => 'First', 'sort_order' => 10]);
        $second = PricingRule::factory()->create(['project_id' => $project->id, 'name' => 'Second', 'sort_order' => 20]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/pricing/rules")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$first->id, $second->id, $third->id], $ids);
    }

    public function test_list_orders_equal_sort_order_rules_by_id_as_a_stable_tiebreak(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $ruleA = PricingRule::factory()->create(['project_id' => $project->id, 'sort_order' => 5]);
        $ruleB = PricingRule::factory()->create(['project_id' => $project->id, 'sort_order' => 5]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/pricing/rules")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$ruleA->id, $ruleB->id], $ids);
    }

    public function test_can_update_a_pricing_rule(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $rule = PricingRule::factory()->create([
            'project_id' => $project->id,
            'name' => 'Original',
            'value' => 10,
            'active' => true,
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/pricing/rules/{$rule->id}", [
                'name' => 'Updated Name',
                'value' => 25,
                'active' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.value', '25.00')
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('pricing_rules', [
            'id' => $rule->id,
            'name' => 'Updated Name',
            'active' => false,
        ]);
    }

    public function test_can_delete_a_pricing_rule_and_it_is_hard_deleted(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $rule = PricingRule::factory()->create(['project_id' => $project->id]);

        $this->withHeaders($this->authHeader($user))
            ->deleteJson("/api/v1/pricing/rules/{$rule->id}")
            ->assertStatus(204);

        // Hard delete is intentional per PricingRuleController's docblock (no proposal
        // snapshot references rules yet) — the row must be entirely gone, not soft-deleted.
        $this->assertDatabaseMissing('pricing_rules', ['id' => $rule->id]);
    }

    public function test_rejects_invalid_type_value(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Bad Rule',
                'type' => 'bonus', // not in markup|fee|discount
                'method' => 'percentage',
                'value' => 10,
                'base_selector' => 'boq_client_subtotal',
            ]);

        $response->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type']]]);
        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Bad Rule']);
    }

    public function test_rejects_invalid_method_value(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Bad Rule',
                'type' => 'fee',
                'method' => 'multiplier', // not in percentage|fixed_amount
                'value' => 10,
                'base_selector' => 'boq_client_subtotal',
            ]);

        $response->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['method']]]);
        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Bad Rule']);
    }

    public function test_rejects_invalid_base_selector_value(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Bad Rule',
                'type' => 'fee',
                'method' => 'percentage',
                'value' => 10,
                'base_selector' => 'total_project_value', // not a real base_selector
            ]);

        $response->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['base_selector']]]);
        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Bad Rule']);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", []);

        $response->assertStatus(422)->assertJsonStructure([
            'error' => ['details' => ['name', 'type', 'method', 'value', 'base_selector']],
        ]);
    }

    public function test_update_also_rejects_invalid_enum_values(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $rule = PricingRule::factory()->create(['project_id' => $project->id, 'type' => 'fee']);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/pricing/rules/{$rule->id}", ['type' => 'not_a_type']);

        $response->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['type']]]);
        $this->assertSame('fee', $rule->fresh()->type);
    }

    public function test_rejects_negative_value(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Negative Rule',
                'type' => 'fee',
                'method' => 'fixed_amount',
                'value' => -100,
                'base_selector' => 'boq_client_subtotal',
            ]);

        $response->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['value']]]);
    }

    public function test_creating_a_rule_for_a_nonexistent_project_returns_not_found(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/projects/999999/pricing/rules', [
                'name' => 'Orphan Rule',
                'type' => 'fee',
                'method' => 'percentage',
                'value' => 10,
                'base_selector' => 'boq_client_subtotal',
            ]);

        $response->assertStatus(404);
    }
}
