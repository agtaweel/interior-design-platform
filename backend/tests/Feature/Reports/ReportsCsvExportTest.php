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
 * PROJECT_CONTEXT.md Sprint 8 "Reports" -> GET /reports/projects/export
 * (ReportController::projectsExport()). Confirms the CSV's numbers match the JSON report's
 * numbers exactly (same ReportService::projectRows() data, just a different serialization) —
 * per the task's explicit instruction, not just that a 200/CSV content-type comes back.
 */
class ReportsCsvExportTest extends TestCase
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

    private function buildProjectWithFinancials(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'name' => 'CSV Export Project',
            'status' => 'active',
        ]);
        $this->priceProject($project, '30000.00', '70000.00');
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id, 'grand_total' => '70000.00']);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '82500.00', // 70000 + 12500 change order
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'percentage' => null,
            'amount' => '82500.00',
            'status' => 'pending',
        ]);
        Payment::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'payment_schedule_id' => $schedule->id,
            'amount' => '20000.00',
        ]);
        ChangeOrder::factory()->applied()->create(['project_id' => $project->id, 'price_delta' => '12500.00']);

        return $project;
    }

    /** @return array<int, array<string, string>> CSV rows keyed by header column name */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $csv)), fn ($l) => $l !== ''));
        $header = str_getcsv($lines[0]);
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $rows[] = array_combine($header, str_getcsv($line));
        }

        return $rows;
    }

    public function test_csv_export_numbers_match_the_json_report_exactly(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->buildProjectWithFinancials($organization);
        $user = $this->financialsUser($organization);

        $jsonResponse = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/projects');
        $jsonResponse->assertStatus(200);
        $jsonRow = collect($jsonResponse->json('data'))->firstWhere('id', $project->id);
        $this->assertNotNull($jsonRow);

        $csvResponse = $this->withHeaders($this->authHeader($user))->get('/api/v1/reports/projects/export');
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('text/csv', $csvResponse->headers->get('Content-Type'));

        $csvRows = $this->parseCsv($csvResponse->streamedContent());
        $csvRow = collect($csvRows)->firstWhere('name', $project->name);
        $this->assertNotNull($csvRow, 'expected the project to appear in the CSV export');

        // Column-by-column comparison against the JSON row's numbers.
        $this->assertSame($jsonRow['name'], $csvRow['name']);
        $this->assertSame($jsonRow['code'], $csvRow['code']);
        $this->assertSame($jsonRow['status'], $csvRow['status']);
        $this->assertSame(0, bccomp($jsonRow['contract_value'], $csvRow['contract_value'], 2));
        $this->assertSame(0, bccomp($jsonRow['collected'], $csvRow['collected'], 2));
        $this->assertSame(0, bccomp($jsonRow['outstanding'], $csvRow['outstanding'], 2));
        $this->assertSame(0, bccomp($jsonRow['estimated_margin'], $csvRow['estimated_margin'], 2));
        $this->assertSame(0, bccomp($jsonRow['change_order_value'], $csvRow['change_order_value'], 2));
        $this->assertSame(0, bccomp($jsonRow['budget_variance'], $csvRow['budget_variance'], 2));

        // Sanity: the specific hand-computed numbers for this fixture.
        $this->assertSame(0, bccomp('82500.00', $csvRow['contract_value'], 2));
        $this->assertSame(0, bccomp('20000.00', $csvRow['collected'], 2));
        $this->assertSame(0, bccomp('62500.00', $csvRow['outstanding'], 2));
        $this->assertSame(0, bccomp('40000.00', $csvRow['estimated_margin'], 2));
        $this->assertSame(0, bccomp('12500.00', $csvRow['change_order_value'], 2));
        $this->assertSame(0, bccomp('12500.00', $csvRow['budget_variance'], 2));
    }

    public function test_csv_export_requires_view_financials_permission(): void
    {
        $organization = Organization::factory()->create();
        $manageBoqOnlyUser = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $this->withHeaders($this->authHeader($manageBoqOnlyUser))
            ->get('/api/v1/reports/projects/export')
            ->assertStatus(403);
    }
}
