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
 * RBAC coverage for Sprint 3's pricing surface: rule mutations (create/update/delete) and
 * POST /pricing/recalculate require Permissions::MANAGE_BOQ (per StorePricingRuleRequest/
 * UpdatePricingRuleRequest/PricingController::recalculate()'s Gate::authorize() call), while
 * GET /pricing/rules and GET /pricing/breakdown only require an active membership — matching the
 * exact posture already established for BOQ reads/writes in BoqRbacTest.
 */
class PricingRbacTest extends TestCase
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

    public function test_read_only_member_can_view_rules_and_breakdown_but_not_mutate_or_recalculate(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
        $rule = PricingRule::factory()->create(['project_id' => $project->id]);

        // No manage_boq permission at all, matching the seeded "Site Staff"-style role.
        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $headers = $this->authHeader($readOnlyUser);

        // --- Reads succeed ---
        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/pricing/rules")
            ->assertStatus(200);
        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/pricing/breakdown")
            ->assertStatus(200);

        // --- Writes/recalculate are forbidden ---
        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Should Fail', 'type' => 'fee', 'method' => 'percentage',
                'value' => 10, 'base_selector' => 'boq_client_subtotal',
            ])
            ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Should Fail']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/pricing/rules/{$rule->id}", ['name' => 'Should Fail'])
            ->assertStatus(403);
        $this->assertNotSame('Should Fail', $rule->fresh()->name);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/pricing/rules/{$rule->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('pricing_rules', ['id' => $rule->id]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/pricing/recalculate")
            ->assertStatus(403);
        $this->assertNull($project->fresh()->priced_at);
    }

    public function test_a_member_with_manage_boq_can_perform_every_pricing_write_operation(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $create = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Design Fee', 'type' => 'fee', 'method' => 'percentage',
                'value' => 12, 'base_selector' => 'boq_client_subtotal',
            ])
            ->assertStatus(201);
        $ruleId = $create->json('data.id');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/pricing/rules/{$ruleId}", ['value' => 15])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/pricing/recalculate")
            ->assertStatus(200);
        $this->assertNotNull($project->fresh()->priced_at);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/pricing/rules/{$ruleId}")
            ->assertStatus(204);
    }
}
