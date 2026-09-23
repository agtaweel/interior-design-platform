<?php

namespace Tests\Feature\Organizations;

use App\Http\Controllers\Api\OrganizationController;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 8 "Settings" (S22, scoped down) -> PATCH /organizations/{organization}
 * (OrganizationController::update() / UpdateOrganizationRequest) and
 * GET /organizations/{organization}/members (OrganizationMemberController::index()).
 */
class OrganizationSettingsTest extends TestCase
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

    // --- PATCH allowed fields ---

    public function test_patch_updates_only_the_allowed_branding_fields(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Original Name',
            'legal_name' => 'Original Legal Name Co.',
            'currency' => 'EGP',
        ]);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_ORGANIZATION => true]);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/organizations/{$organization->id}", [
                'name' => 'New Name',
                'phone' => '+201000000000',
                'email' => 'contact@neworg.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '+201000000000')
            ->assertJsonPath('data.email', 'contact@neworg.com')
            // Untouched field retains its original value.
            ->assertJsonPath('data.legal_name', 'Original Legal Name Co.');

        $fresh = $organization->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('Original Legal Name Co.', $fresh->legal_name);
    }

    /**
     * `settings_json` is a real column on organizations (the locked "multi-branch flexibility"
     * escape hatch) but is deliberately NOT in UpdateOrganizationRequest::rules() — per that
     * class's docblock, this endpoint's field list is exactly the seven branding columns, not a
     * general settings bag. An attempt to smuggle it (or any other unlisted field, like `id`)
     * through the payload must be silently ignored, not applied.
     */
    public function test_patch_ignores_fields_outside_the_documented_allow_list(): void
    {
        $organization = Organization::factory()->create(['settings_json' => ['feature_x' => false]]);
        $otherOrgId = Organization::factory()->create()->id;
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_ORGANIZATION => true]);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/organizations/{$organization->id}", [
                'name' => 'Allowed Change',
                'settings_json' => ['feature_x' => true],
                'id' => $otherOrgId,
            ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'Allowed Change');

        $fresh = $organization->fresh();
        $this->assertSame(['feature_x' => false], $fresh->settings_json);
        $this->assertSame($organization->id, $fresh->id);
    }

    // --- RBAC ---

    public function test_patch_requires_manage_organization_permission(): void
    {
        $organization = Organization::factory()->create(['name' => 'Untouched']);
        // Has MANAGE_BOQ (a real, meaningful permission elsewhere) but NOT MANAGE_ORGANIZATION.
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/organizations/{$organization->id}", ['name' => 'Should Fail']);

        $response->assertStatus(403);
        $this->assertSame('Untouched', $organization->fresh()->name);
    }

    /**
     * Belt-and-suspenders tenant check (OrganizationController::update()'s docblock): reject a
     * PATCH whose {organization} route id does not match the current TenantContext.
     *
     * IMPORTANT, documented rather than silently worked around: under the ACTUAL routing setup
     * (routes/api.php), this branch is unreachable via a real HTTP request. {organization} is an
     * implicitly-bound Eloquent route parameter, and App\Http\Middleware\ResolveTenantContext's
     * resolution order #1 derives TenantContext directly FROM that same bound model — so
     * $organization->id and TenantContext::organizationId() agree by construction on every real
     * request that reaches the controller (either they match, or the middleware itself already
     * 403'd for lack of an active membership in that org before the controller ever runs). This
     * test therefore invokes the controller method directly, bypassing HTTP/FormRequest
     * resolution, to exercise the guard clause itself in isolation — confirming the defensive
     * code is correct and would fire correctly if the routing/middleware relationship ever
     * changed, even though today it is not reachable end-to-end. Flagged in the QA report as a
     * "currently-dead defensive branch", not a bug.
     */
    public function test_patch_rejects_a_different_organizations_id_than_the_current_tenant_context(): void
    {
        $tenantOrg = Organization::factory()->create();
        $otherOrg = Organization::factory()->create(['name' => 'Other Org Untouched']);

        app(TenantContext::class)->set($tenantOrg->id);

        $controller = new OrganizationController();
        $request = new UpdateOrganizationRequest();

        $response = $controller->update($request, $otherOrg);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('tenant_mismatch', $response->getData(true)['error']['code']);
        $this->assertSame('Other Org Untouched', $otherOrg->fresh()->name);

        app(TenantContext::class)->clear();
    }

    // --- GET /organizations/{id}/members ---

    public function test_members_index_lists_every_membership_for_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, []);
        $role = Role::factory()->create(['organization_id' => null, 'name' => 'Designer']);
        $invited = User::factory()->create(['name' => 'Invited Person', 'email' => 'invited@example.com']);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $invited->id,
            'role_id' => $role->id,
            'status' => 'invited',
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/organizations/{$organization->id}/members");

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $emails = collect($response->json('data'))->pluck('user.email')->all();
        $this->assertContains('invited@example.com', $emails);
        $statuses = collect($response->json('data'))->pluck('status')->all();
        $this->assertContains('invited', $statuses);
        $this->assertContains('active', $statuses);
    }

    public function test_members_index_is_tenant_isolated(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, []);
        $this->memberWithPermissions($orgB, []); // a member that only belongs to org B

        $response = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/organizations/{$orgA->id}/members");
        $response->assertStatus(200)->assertJsonCount(1, 'data');

        // userA has no active membership in orgB at all -> tenant middleware itself rejects it
        // before the controller ever filters by organization_id.
        $crossOrg = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/organizations/{$orgB->id}/members");
        $crossOrg->assertStatus(403);
    }
}
