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
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Extends the tenant-isolation guarantee (tests/Feature/Tenancy/TenantIsolationTest.php,
 * tests/Feature/Boq/BoqTenantIsolationTest.php) to Sprint 3's pricing surface: a user
 * authenticated into organization A must never be able to read or write organization B's
 * pricing_rules, or recalculate/view the breakdown for organization B's project, even by
 * guessing/enumerating ids. PricingRule has no organization_id of its own (scoped indirectly via
 * project_id -> projects.organization_id, see model docblock) — this test proves
 * resolveTenantScopedRule()'s re-verification via Project::find() actually holds.
 */
class PricingTenantIsolationTest extends TestCase
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

    public function test_cannot_list_or_create_pricing_rules_for_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        PricingRule::factory()->create(['project_id' => $projectB->id, 'name' => 'Org B Rule']);

        $headersA = $this->authHeader($userA);

        $this->withHeaders($headersA)
            ->getJson("/api/v1/projects/{$projectB->id}/pricing/rules")
            ->assertStatus(404);

        $create = $this->withHeaders($headersA)
            ->postJson("/api/v1/projects/{$projectB->id}/pricing/rules", [
                'name' => 'Intruder Rule', 'type' => 'fee', 'method' => 'percentage',
                'value' => 10, 'base_selector' => 'boq_client_subtotal',
            ]);
        $create->assertStatus(404);
        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Intruder Rule']);
    }

    public function test_cannot_update_or_delete_another_organizations_pricing_rule_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $ruleB = PricingRule::factory()->create(['project_id' => $projectB->id, 'name' => 'Original']);

        $headersA = $this->authHeader($userA);

        $this->withHeaders($headersA)
            ->patchJson("/api/v1/pricing/rules/{$ruleB->id}", ['name' => 'Hacked'])
            ->assertStatus(404);
        $this->assertSame('Original', $ruleB->fresh()->name);

        $this->withHeaders($headersA)
            ->deleteJson("/api/v1/pricing/rules/{$ruleB->id}")
            ->assertStatus(404);
        $this->assertDatabaseHas('pricing_rules', ['id' => $ruleB->id]);
    }

    public function test_cannot_recalculate_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $category = BoqCategory::factory()->create(['project_id' => $projectB->id]);
        BoqItem::factory()->create(['project_id' => $projectB->id, 'category_id' => $category->id]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/pricing/recalculate")
            ->assertStatus(404);

        // Org B's project must remain unpriced — org A's attempt must not have written anything.
        $this->assertNull($projectB->fresh()->priced_at);
    }

    public function test_cannot_view_another_organizations_pricing_breakdown(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $userB = $this->fullAccessUser($orgB);
        $projectB = $this->projectIn($orgB);
        $category = BoqCategory::factory()->create(['project_id' => $projectB->id]);
        BoqItem::factory()->create(['project_id' => $projectB->id, 'category_id' => $category->id]);

        // Org B actually recalculates/prices their own project first.
        $this->withHeaders($this->authHeader($userB))
            ->postJson("/api/v1/projects/{$projectB->id}/pricing/recalculate")
            ->assertStatus(200);

        // Auth::forgetGuards() is required here, not decoration: Sanctum's guard memoizes the
        // user it resolved for the FIRST authenticated request made within a test method, so a
        // second real HTTP call authenticating as a different user (userA) would otherwise
        // still resolve as userB internally — a testing-harness artifact of reusing one
        // application/container across sequential requests in-process, not a real production
        // vulnerability (a real deployment has no cross-request PHP state to leak through).
        // Confirmed by reproducing this exact failure without the call (the second request's
        // `organization_members` query used userB's id even though userA's bearer token was
        // sent), then confirming it disappears once guards are forgotten before switching.
        Auth::forgetGuards();

        // Org A must not be able to read org B's now-populated breakdown.
        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectB->id}/pricing/breakdown")
            ->assertStatus(404);
    }
}
