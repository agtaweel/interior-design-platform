<?php

namespace Tests\Feature\Payments;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProposalVersion;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRD §8 "Project Profitability Engine": quoted cost, committed cost, actual cost, gross
 * profit, margin % — computed from real Procurement (PurchaseOrder/PurchaseOrderItem) and
 * Expenses data via ProjectCostCalculator, replacing the old Sprint 6 placeholders.
 */
class ProjectCostCalculatorTest extends TestCase
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

    public function test_quoted_committed_and_actual_cost_are_distinguished(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $supplier = Supplier::factory()->create(['organization_id' => $organization->id]);
        $user = $this->memberWithPermissions($organization, [
            Permissions::VIEW_FINANCIALS => true,
            Permissions::MANAGE_PROCUREMENT => true,
        ]);

        // Draft PO: counts toward quoted_cost only (not yet committed).
        $draftOrder = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'draft']);
        $draftOrder->items()->create(['description' => 'Draft item', 'unit' => 'pcs', 'quantity' => 1, 'quoted_unit_price' => 1000]);

        // Sent PO with a fully received line: counts toward quoted_cost, committed_cost, and
        // actual_cost (at the actual price, which differs from quoted).
        $sentOrder = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'received', 'sent_at' => now()]);
        $sentItem = $sentOrder->items()->create([
            'description' => 'Tiles', 'unit' => 'm2', 'quantity' => 10, 'quoted_unit_price' => 800,
        ]);
        // received_quantity/actual_unit_price are deliberately excluded from
        // PurchaseOrderItem's #[Fillable] (see that model's docblock) — forceFill(), not
        // mass-assignment through create(), matching how PurchaseOrderController::receive()
        // itself sets them.
        $sentItem->forceFill(['received_quantity' => 10, 'actual_unit_price' => 850])->save();

        // Cancelled PO: excluded from every total.
        $cancelledOrder = PurchaseOrder::factory()->create(['project_id' => $project->id, 'supplier_id' => $supplier->id, 'status' => 'cancelled']);
        $cancelledOrder->items()->create(['description' => 'Ignored', 'unit' => 'pcs', 'quantity' => 1, 'quoted_unit_price' => 5000]);

        ProjectExpense::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id, 'amount' => '2000.00']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        // quoted: 1000 (draft) + 8000 (sent, at quoted price) = 9000; cancelled excluded.
        $this->assertSame('9000.00', $response->json('data.quoted_cost'));
        // committed: only the sent/received order's quoted total = 8000.
        $this->assertSame('8000.00', $response->json('data.committed_cost'));
        // actual: received line at ACTUAL price (10 * 850 = 8500) + expense (2000) = 10500.
        $this->assertSame('10500.00', $response->json('data.actual_cost'));
    }

    public function test_gross_profit_and_margin_percent_are_computed_against_contract_value(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '100000.00',
        ]);
        ProjectExpense::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id, 'amount' => '40000.00']);
        $user = $this->memberWithPermissions($organization, [Permissions::VIEW_FINANCIALS => true]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $this->assertSame('40000.00', $response->json('data.actual_cost'));
        $this->assertSame('60000.00', $response->json('data.gross_profit'));
        $this->assertSame('60.00', $response->json('data.margin_percent'));
    }

    public function test_gross_profit_and_margin_percent_are_null_without_a_contract(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, [Permissions::VIEW_FINANCIALS => true]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $this->assertNull($response->json('data.gross_profit'));
        $this->assertNull($response->json('data.margin_percent'));
        $this->assertSame(0, $response->json('data.actual_cost'));
    }

    public function test_reports_projects_expose_the_same_cost_fields(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id, 'name' => 'Cost Report Project']);
        ProjectExpense::factory()->create(['project_id' => $project->id, 'organization_id' => $organization->id, 'amount' => '500.00']);
        $user = $this->memberWithPermissions($organization, [Permissions::VIEW_FINANCIALS => true]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/reports/projects');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('name', 'Cost Report Project');
        $this->assertNotNull($row);
        $this->assertSame('500.00', $row['actual_cost']);
        $this->assertArrayHasKey('quoted_cost', $row);
        $this->assertArrayHasKey('committed_cost', $row);
        $this->assertArrayHasKey('margin_percent', $row);
    }
}
