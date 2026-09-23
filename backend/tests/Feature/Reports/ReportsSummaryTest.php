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
 * PROJECT_CONTEXT.md Sprint 8 "Reports" (S21) -> GET /reports/summary (ReportController::summary()
 * / ReportService::summary()). Hand-computes the expected KPI values against a small, fully
 * controlled fixture scenario (3 projects: one fully "normal" with a contract/payments, one with
 * an APPLIED change order to exercise the contract-value-drift math, one with no contract at
 * all) rather than trusting the implementation's own arithmetic — per the task's instruction not
 * to just re-derive the same formula the code uses.
 *
 * Fixture built directly via factories/forceFill (not the full BOQ->pricing->proposal->send->
 * approve->contract->payment HTTP flow) since ReportService reads only the already-cached
 * pricing columns (projects.direct_cost_total/grand_total/priced_at), contract_value, and
 * payments/change_orders rows — every one of those flows is already exhaustively tested
 * end-to-end elsewhere (PricingRecalculationTest, ContractConversionTest, PaymentIdempotencyTest,
 * ChangeOrderApplyTest); duplicating all of that machinery here would test the same code paths
 * twice while making the hand-computed sums much harder to verify by eye.
 */
class ReportsSummaryTest extends TestCase
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
     * #[Fillable] list (they're only ever written by PricingRecalculationService) — mass
     * assignment via Project::factory()->create([...]) silently drops them, same reason
     * ProjectPricingClientResourceTest forceFill()s them directly instead.
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
     * Builds the shared 3-project fixture used by this class and ReportsProjectsTest's
     * equivalent scenario:
     *
     *  - Project 1 ("normal"): priced (direct_cost=50000, grand_total=100000), contract signed
     *    at exactly the proposal's grand_total (100000, no change orders), 40000 collected.
     *  - Project 2 ("changed"): priced (direct_cost=50000, grand_total=80000), proposal frozen
     *    at 80000, contract_value bumped to 95000 by one APPLIED 15000 change order, 30000
     *    collected. This is the fixture that exercises budget_variance == change_order_value.
     *  - Project 3 ("no contract yet"): not priced, no proposal, no contract, no payments —
     *    must not crash and must report sensible zero/null-safe values.
     *
     * @return array{0: Organization, 1: Project, 2: Project, 3: Project}
     */
    private function buildScenario(): array
    {
        $organization = Organization::factory()->create();

        // --- Project 1: normal, no change orders ---
        $client1 = Client::factory()->create(['organization_id' => $organization->id]);
        $project1 = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client1->id,
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

        // --- Project 2: has one APPLIED change order that bumped contract_value ---
        $client2 = Client::factory()->create(['organization_id' => $organization->id]);
        $project2 = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client2->id,
            'status' => 'active',
        ]);
        $this->priceProject($project2, '50000.00', '80000.00');
        $proposal2 = ProposalVersion::factory()->approved()->create([
            'project_id' => $project2->id,
            'grand_total' => '80000.00', // frozen at signing, BEFORE the change order
        ]);
        $contract2 = Contract::factory()->create([
            'project_id' => $project2->id,
            'proposal_version_id' => $proposal2->id,
            'contract_value' => '95000.00', // 80000 + 15000 change order, as if already applied
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
        // A draft change order on the same project must NOT count toward change_order_value —
        // only 'applied' ones do.
        ChangeOrder::factory()->create([
            'project_id' => $project2->id,
            'price_delta' => null,
        ]);

        // --- Project 3: no contract, no pricing at all ---
        $client3 = Client::factory()->create(['organization_id' => $organization->id]);
        $project3 = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client3->id,
            'status' => 'draft',
            'direct_cost_total' => null,
            'grand_total' => null,
            'priced_at' => null,
        ]);

        return [$organization, $project1, $project2, $project3];
    }

    public function test_summary_kpis_match_hand_computed_values_for_the_fixture_scenario(): void
    {
        [$organization] = $this->buildScenario();
        $user = $this->financialsUser($organization);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/summary');

        $response->assertStatus(200);
        $data = $response->json('data');

        // total_revenue = sum of ALL payments.amount org-wide = 40000 + 30000
        $this->assertSame(0, bccomp('70000.00', $data['total_revenue'], 2));

        // total_receivables = sum(contract_value - collected) across every project WITH a
        // contract, NOT clamped at zero per-project: (100000-40000) + (95000-30000)
        $this->assertSame(0, bccomp('125000.00', $data['total_receivables'], 2));

        // estimated_margin = sum(grand_total - direct_cost_total) across every PRICED project:
        // (100000-50000) + (80000-50000) — project 3 is unpriced and contributes nothing.
        $this->assertSame(0, bccomp('80000.00', $data['estimated_margin'], 2));

        // total_change_order_value = sum of price_delta across every APPLIED change order
        // org-wide = 15000 (the draft change order on project 2 must not be counted).
        $this->assertSame(0, bccomp('15000.00', $data['total_change_order_value'], 2));

        $this->assertSame(3, $data['project_count']);
        $this->assertSame(2, $data['active_project_count']); // project 3 is 'draft'
    }

    public function test_summary_does_not_crash_when_every_project_has_no_contract_or_pricing(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => 'draft',
        ]);
        $user = $this->financialsUser($organization);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/summary');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame(0, bccomp('0.00', $data['total_revenue'], 2));
        $this->assertSame(0, bccomp('0.00', $data['total_receivables'], 2));
        $this->assertSame(0, bccomp('0.00', $data['estimated_margin'], 2));
        $this->assertSame(0, bccomp('0.00', $data['total_change_order_value'], 2));
        $this->assertSame(1, $data['project_count']);
    }

    public function test_summary_is_tenant_isolated(): void
    {
        [$orgA] = $this->buildScenario();
        $orgB = Organization::factory()->create();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        $projectB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'client_id' => $clientB->id,
        ]);
        $this->priceProject($projectB, '999999.00', '999999.00');
        $proposalB = ProposalVersion::factory()->approved()->create(['project_id' => $projectB->id, 'grand_total' => '999999.00']);
        $contractB = Contract::factory()->create([
            'project_id' => $projectB->id,
            'proposal_version_id' => $proposalB->id,
            'contract_value' => '999999.00',
        ]);
        Payment::factory()->create([
            'organization_id' => $orgB->id,
            'project_id' => $projectB->id,
            'payment_schedule_id' => PaymentSchedule::factory()->create(['contract_id' => $contractB->id])->id,
            'amount' => '999999.00',
        ]);

        $userA = $this->financialsUser($orgA);
        $response = $this->withHeaders($this->authHeader($userA))->getJson('/api/v1/reports/summary');

        $response->assertStatus(200);
        $data = $response->json('data');
        // Org A's own totals (asserted precisely above in the main test) must be unaffected by
        // org B's much larger figures.
        $this->assertSame(0, bccomp('70000.00', $data['total_revenue'], 2));
        $this->assertSame(3, $data['project_count']);
    }

    // --- RBAC ---

    public function test_summary_requires_view_financials_permission(): void
    {
        $organization = Organization::factory()->create();
        $manageBoqOnlyUser = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $response = $this->withHeaders($this->authHeader($manageBoqOnlyUser))->getJson('/api/v1/reports/summary');

        $response->assertStatus(403);
    }

    public function test_summary_is_accessible_with_view_financials_permission(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->financialsUser($organization);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/summary');

        $response->assertStatus(200);
    }
}
