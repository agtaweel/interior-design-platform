<?php

namespace Tests\Feature\Reports;

use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 8 "Reports" (S21) -> GET /reports/projects (ReportController::
 * projects() / ReportService::projectRows()). Same 3-project fixture shape as
 * ReportsSummaryTest (see that class's buildScenario() docblock for the full scenario
 * description) but asserting the PER-PROJECT row values, and in particular the
 * budget_variance == change_order_value invariant PROJECT_CONTEXT.md calls out explicitly as
 * "a good internal consistency check for QA to verify, not just a display figure to trust
 * blindly" — this is the critical regression-catcher: budget_variance is computed independently
 * (contract_value - contract.proposalVersion.grand_total) from change_order_value (sum of
 * applied change_orders.price_delta), and by construction (ChangeOrderApplyService is the ONLY
 * code path that ever moves contract_value after creation, and it adds exactly price_delta)
 * these two numbers must always agree. If a future change made contract_value driftable through
 * some OTHER path (e.g. a manual PATCH bypassing the guard), this test would catch the drift.
 */
class ReportsProjectsTest extends TestCase
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

    private function financialsUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::VIEW_FINANCIALS => true]);
    }

    /**
     * projects.direct_cost_total/grand_total/priced_at are deliberately NOT in Project's
     * #[Fillable] list (only PricingRecalculationService writes them) — mass assignment via
     * Project::factory()->create([...]) silently drops them, so they're set via forceFill()
     * instead, same as ProjectPricingClientResourceTest.
     */
    private function priceProject(Project $project, string $directCostTotal, string $grandTotal): void
    {
        $project->forceFill([
            'direct_cost_total' => $directCostTotal,
            'grand_total' => $grandTotal,
            'priced_at' => now(),
        ])->save();
    }

    /**
     * @return array{0: Organization, 1: Project, 2: Project, 3: Project}
     */
    private function buildScenario(): array
    {
        $organization = Organization::factory()->create();

        $client1 = Client::factory()->create(['organization_id' => $organization->id]);
        $project1 = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client1->id,
            'name' => 'Downtown Apartment',
            'status' => 'active',
        ]);
        $this->priceProject($project1, '50000.00', '100000.00');
        $proposal1 = ProposalVersion::factory()->approved()->create([
            'project_id' => $project1->id,
            'grand_total' => '100000.00',
        ]);
        $contract1 = Contract::factory()->create([
            'project_id' => $project1->id,
            'proposal_version_id' => $proposal1->id,
            'contract_value' => '100000.00',
        ]);
        $schedule1 = PaymentSchedule::factory()->create([
            'contract_id' => $contract1->id,
            'sequence_no' => 1,
            'percentage' => null,
            'amount' => '100000.00',
            'status' => 'pending',
        ]);
        Payment::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project1->id,
            'payment_schedule_id' => $schedule1->id,
            'amount' => '40000.00',
        ]);

        $client2 = Client::factory()->create(['organization_id' => $organization->id]);
        $project2 = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client2->id,
            'name' => 'Villa Renovation',
            'status' => 'active',
        ]);
        $this->priceProject($project2, '50000.00', '80000.00');
        $proposal2 = ProposalVersion::factory()->approved()->create([
            'project_id' => $project2->id,
            'grand_total' => '80000.00',
        ]);
        $contract2 = Contract::factory()->create([
            'project_id' => $project2->id,
            'proposal_version_id' => $proposal2->id,
            'contract_value' => '95000.00',
        ]);
        $schedule2 = PaymentSchedule::factory()->create([
            'contract_id' => $contract2->id,
            'sequence_no' => 1,
            'percentage' => null,
            'amount' => '95000.00',
            'status' => 'pending',
        ]);
        Payment::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project2->id,
            'payment_schedule_id' => $schedule2->id,
            'amount' => '30000.00',
        ]);
        ChangeOrder::factory()->applied()->create([
            'project_id' => $project2->id,
            'price_delta' => '15000.00',
        ]);

        $client3 = Client::factory()->create(['organization_id' => $organization->id]);
        $project3 = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client3->id,
            'name' => 'No Contract Yet',
            'status' => 'draft',
            'direct_cost_total' => null,
            'grand_total' => null,
            'priced_at' => null,
        ]);

        return [$organization, $project1, $project2, $project3];
    }

    private function rowFor(array $rows, int $projectId): array
    {
        $row = collect($rows)->firstWhere('id', $projectId);
        $this->assertNotNull($row, "no report row found for project {$projectId}");

        return $row;
    }

    public function test_project_rows_match_hand_computed_values_including_the_critical_budget_variance_invariant(): void
    {
        [$organization, $project1, $project2, $project3] = $this->buildScenario();
        $user = $this->financialsUser($organization);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/projects');
        $response->assertStatus(200);
        $rows = $response->json('data');
        $this->assertCount(3, $rows);

        // --- Project 1: no change orders -> budget_variance is trivially 0 ---
        $row1 = $this->rowFor($rows, $project1->id);
        $this->assertSame(0, bccomp('100000.00', $row1['contract_value'], 2));
        $this->assertSame(0, bccomp('40000.00', $row1['collected'], 2));
        $this->assertSame(0, bccomp('60000.00', $row1['outstanding'], 2));
        $this->assertSame(0, bccomp('50000.00', $row1['estimated_margin'], 2));
        $this->assertSame(0, bccomp('0.00', $row1['change_order_value'], 2));
        $this->assertSame(0, bccomp('0.00', $row1['budget_variance'], 2));

        // --- Project 2: the critical invariant — budget_variance MUST equal
        //     change_order_value exactly (both 15000.00) ---
        $row2 = $this->rowFor($rows, $project2->id);
        $this->assertSame(0, bccomp('95000.00', $row2['contract_value'], 2));
        $this->assertSame(0, bccomp('30000.00', $row2['collected'], 2));
        $this->assertSame(0, bccomp('65000.00', $row2['outstanding'], 2));
        $this->assertSame(0, bccomp('30000.00', $row2['estimated_margin'], 2));
        $this->assertSame(0, bccomp('15000.00', $row2['change_order_value'], 2));
        $this->assertSame(0, bccomp('15000.00', $row2['budget_variance'], 2));
        $this->assertSame(
            0,
            bccomp($row2['change_order_value'], $row2['budget_variance'], 2),
            'budget_variance must equal change_order_value exactly for a project with applied change orders'
        );

        // --- Project 3: no contract, no pricing -> zeros everywhere, no crash ---
        $row3 = $this->rowFor($rows, $project3->id);
        $this->assertSame(0, bccomp('0.00', $row3['contract_value'], 2));
        $this->assertSame(0, bccomp('0.00', $row3['collected'], 2));
        $this->assertSame(0, bccomp('0.00', $row3['outstanding'], 2));
        $this->assertSame(0, bccomp('0.00', $row3['estimated_margin'], 2));
        $this->assertSame(0, bccomp('0.00', $row3['change_order_value'], 2));
        $this->assertSame(0, bccomp('0.00', $row3['budget_variance'], 2));
    }

    /**
     * A second, independent scenario with a NEGATIVE change order (a removal), to prove the
     * budget_variance == change_order_value invariant holds in both directions, not just for
     * positive deltas.
     */
    public function test_budget_variance_equals_change_order_value_for_a_negative_change_order_too(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
        ]);
        $this->priceProject($project, '20000.00', '50000.00');
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id, 'grand_total' => '50000.00']);
        Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '45000.00', // 50000 - 5000 removal
        ]);
        ChangeOrder::factory()->applied()->create(['project_id' => $project->id, 'price_delta' => '-5000.00']);

        $user = $this->financialsUser($organization);
        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/projects');
        $row = $this->rowFor($response->json('data'), $project->id);

        $this->assertSame(0, bccomp('-5000.00', $row['change_order_value'], 2));
        $this->assertSame(0, bccomp('-5000.00', $row['budget_variance'], 2));
    }

    public function test_project_rows_are_tenant_isolated(): void
    {
        [$orgA] = $this->buildScenario();
        $orgB = Organization::factory()->create();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        Project::factory()->create(['organization_id' => $orgB->id, 'client_id' => $clientB->id, 'name' => 'Org B Project']);

        $userA = $this->financialsUser($orgA);
        $response = $this->withHeaders($this->authHeader($userA))->getJson('/api/v1/reports/projects');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('Org B Project', $names);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_project_rows_requires_view_financials_permission(): void
    {
        $organization = Organization::factory()->create();
        $manageBoqOnlyUser = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $this->withHeaders($this->authHeader($manageBoqOnlyUser))
            ->getJson('/api/v1/reports/projects')
            ->assertStatus(403);
    }
}
