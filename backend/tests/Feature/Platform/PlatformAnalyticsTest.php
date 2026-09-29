<?php

namespace Tests\Feature\Platform;

use App\Models\Client;
use App\Models\FinancialTransaction;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\FinancialLedgerService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRD v3 §5/§20 "Platform Owner / Super Admin" (EnsurePlatformOwner, PlatformAnalyticsController).
 * The load-bearing case: financial figures correctly aggregate ACROSS organizations, since that
 * cross-tenant visibility is the entire point of this role.
 */
class PlatformAnalyticsTest extends TestCase
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

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    private function platformOwner(): User
    {
        return User::factory()->create(['is_platform_owner' => true]);
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    /**
     * Posts a contract_charge (and, if $paid > 0, a client_payment) directly through
     * FinancialLedgerService for the given org/project — bypassing HTTP entirely. This test is
     * about cross-organization AGGREGATION (PlatformAnalyticsController reading
     * FinancialTransaction rows regardless of how they were posted), not the write path itself
     * (already covered by FinancialLedgerTest's Golden Financial Test Case) — going through
     * multiple real HTTP requests as two DIFFERENT users in the same test method hits a known
     * Sanctum test-harness quirk in this codebase (the second user's request can resolve as the
     * first internally), so seeding directly sidesteps it rather than fighting it.
     */
    private function seedFinancials(Organization $organization, Project $project, User $actor, string $contractValue, string $paid): void
    {
        $ledger = app(FinancialLedgerService::class);

        $ledger->post([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope' => FinancialTransaction::SCOPE_CLIENT,
            'type' => FinancialTransaction::TYPE_CONTRACT_CHARGE,
            'amount' => $contractValue,
            'transaction_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);

        if (bccomp($paid, '0.00', 2) > 0) {
            $ledger->post([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'scope' => FinancialTransaction::SCOPE_CLIENT,
                'type' => FinancialTransaction::TYPE_CLIENT_PAYMENT,
                'amount' => $paid,
                'transaction_date' => now()->toDateString(),
                'created_by' => $actor->id,
            ]);
        }
    }

    public function test_non_platform_owner_is_rejected_with_403(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/platform/analytics/summary')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'platform_owner_required');
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/v1/platform/analytics/summary')->assertStatus(401);
    }

    public function test_summary_aggregates_financials_across_multiple_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $userB = $this->fullAccessUser($orgB);
        $projectA = $this->projectIn($orgA);
        $projectB = $this->projectIn($orgB);

        $this->seedFinancials($orgA, $projectA, $userA, '100000.00', '40000.00');
        $this->seedFinancials($orgB, $projectB, $userB, '50000.00', '50000.00');

        $owner = $this->platformOwner();
        $response = $this->withHeaders($this->authHeader($owner))
            ->getJson('/api/v1/platform/analytics/summary');

        $response->assertStatus(200);
        $this->assertSame('150000.00', $response->json('data.financials.obligation'));
        $this->assertSame('90000.00', $response->json('data.financials.paid'));
        $this->assertSame('60000.00', $response->json('data.financials.outstanding'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.organizations_count'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.projects_count'));
    }

    public function test_organizations_list_includes_project_and_member_counts(): void
    {
        $org = Organization::factory()->create(['name' => 'Zed Interiors']);
        $user = $this->fullAccessUser($org);
        $this->projectIn($org);
        $this->projectIn($org);

        $owner = $this->platformOwner();
        $response = $this->withHeaders($this->authHeader($owner))
            ->getJson('/api/v1/platform/organizations');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('name', 'Zed Interiors');
        $this->assertNotNull($row);
        $this->assertSame(2, $row['projects_count']);
        $this->assertGreaterThanOrEqual(1, $row['members_count']);
        // Never leaks a per-user identifier list, just the aggregate count.
        $this->assertArrayNotHasKey('users', $row);
    }

    public function test_organization_drill_down_returns_scoped_financials(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $userB = $this->fullAccessUser($orgB);
        $projectA = $this->projectIn($orgA);
        $projectB = $this->projectIn($orgB);

        $this->seedFinancials($orgA, $projectA, $userA, '100000.00', '25000.00');
        $this->seedFinancials($orgB, $projectB, $userB, '999999.00', '0.00');

        $owner = $this->platformOwner();
        $response = $this->withHeaders($this->authHeader($owner))
            ->getJson("/api/v1/platform/organizations/{$orgA->id}");

        $response->assertStatus(200);
        $this->assertSame($orgA->id, $response->json('data.id'));
        $this->assertSame('100000.00', $response->json('data.financials.obligation'));
        $this->assertSame('25000.00', $response->json('data.financials.paid'));
        $this->assertSame('75000.00', $response->json('data.financials.outstanding'));
    }

    public function test_platform_owner_command_grants_and_revokes_access(): void
    {
        $user = User::factory()->create(['is_platform_owner' => false]);

        $this->artisan('platform:owner', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_owner);

        $this->artisan('platform:owner', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_owner);
    }
}
