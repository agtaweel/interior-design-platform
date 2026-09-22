<?php

namespace Tests\Feature\Proposals;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalItem;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Extends the tenant-isolation guarantee (tests/Feature/Tenancy/TenantIsolationTest.php,
 * tests/Feature/Boq/BoqTenantIsolationTest.php, tests/Feature/Pricing/PricingTenantIsolationTest.php)
 * to Sprint 4's proposals surface. This is the first TWO-HOP indirectly-scoped model in the
 * codebase: proposal_items -> proposal_versions.project_id -> projects.organization_id (one hop
 * deeper than BoqItem/PricingRule's single hop) — see ProposalItem's model docblock. There is no
 * direct route to list proposal_items on their own, so proving org A cannot read org B's
 * proposal_version (via index/show/pdf) is what proves org A cannot reach org B's proposal_items
 * either, since items are only ever exposed embedded inside their parent version's response.
 */
class ProposalTenantIsolationTest extends TestCase
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

    private function seedBoqItem(Project $project): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
    }

    /** Builds org B's draft proposal directly (not via HTTP, since we're org A in these tests). */
    private function proposalWithItemsIn(Project $project): ProposalVersion
    {
        $version = ProposalVersion::factory()->create(['project_id' => $project->id, 'version_no' => 1]);
        ProposalItem::factory()->create([
            'proposal_version_id' => $version->id,
            'description' => 'Org B Secret Line Item',
        ]);

        return $version;
    }

    public function test_cannot_list_another_organizations_project_proposals(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $this->proposalWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectB->id}/proposals")
            ->assertStatus(404);
    }

    public function test_cannot_create_a_proposal_for_another_organizations_project(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $this->seedBoqItem($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectB->id}/proposals")
            ->assertStatus(404);

        $this->assertDatabaseCount('proposal_versions', 0);
    }

    public function test_cannot_view_another_organizations_proposal_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $versionB = $this->proposalWithItemsIn($projectB);

        $response = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/proposals/{$versionB->id}");

        $response->assertStatus(404);
        // The two-hop-scoped secret line item must not leak into the 404 body either.
        $this->assertStringNotContainsString('Org B Secret Line Item', $response->getContent());
    }

    public function test_cannot_patch_another_organizations_proposal_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $versionB = $this->proposalWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->patchJson("/api/v1/proposals/{$versionB->id}", ['content_json' => ['cover_note' => 'Hacked']])
            ->assertStatus(404);

        $this->assertNotSame('Hacked', $versionB->fresh()->content_json['cover_note'] ?? null);
    }

    public function test_cannot_send_another_organizations_proposal_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $versionB = $this->proposalWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/proposals/{$versionB->id}/send")
            ->assertStatus(404);

        $this->assertSame('draft', $versionB->fresh()->status);
        $this->assertDatabaseCount('signed_links', 0);
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_cannot_download_another_organizations_proposal_pdf_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectB = $this->projectIn($orgB);
        $versionB = $this->proposalWithItemsIn($projectB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/proposals/{$versionB->id}/pdf")
            ->assertStatus(404);
    }

    public function test_org_a_user_sees_only_org_as_own_proposals_when_both_orgs_have_them(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $projectA = $this->projectIn($orgA);
        $projectB = $this->projectIn($orgB);
        $this->seedBoqItem($projectA);
        $this->proposalWithItemsIn($projectB);

        // Org A creates its own proposal for its own project.
        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/projects/{$projectA->id}/proposals")
            ->assertStatus(201);

        // Auth::forgetGuards() precaution matching PricingTenantIsolationTest's documented
        // rationale — Sanctum's guard memoizes per-test-method; not needed here since only
        // userA authenticates in this test, but kept for consistency/safety if this test is
        // ever extended to switch users.
        Auth::forgetGuards();

        $index = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectA->id}/proposals")
            ->assertStatus(200);

        $this->assertCount(1, $index->json('data'));
        // Org B's proposal is never visible even indirectly through org A's own list endpoint.
        $this->assertDatabaseCount('proposal_versions', 2);
    }
}
