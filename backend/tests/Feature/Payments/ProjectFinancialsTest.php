<?php

namespace Tests\Feature\Payments;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 6 -> GET /projects/{id}/financials (ProjectFinancialsController) and
 * the `financials` block in GET /projects/{id} (ProjectResource), both backed by the SAME
 * ProjectFinancialsCalculator instance.
 *
 * Per ProjectFinancialsCalculator::resolveOutstanding()'s documented judgment call: with no
 * contract yet, `outstanding` is 0.00 (NOT the project's priced `value`) — a receivable can't
 * exist before a contract commits the client to it. Once a contract exists, `outstanding =
 * contract_value - collected`, clamped at 0 (never negative) on overpayment.
 *
 * `actual_cost`/`gross_profit` stay at their Sprint 1/3 placeholders (0 / null) per this
 * sprint's explicit scope boundary — confirmed unchanged here rather than assumed.
 */
class ProjectFinancialsTest extends TestCase
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
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);
    }

    private function projectIn(Organization $organization, array $attributes = []): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
        ], $attributes));
    }

    private function recordPayment(User $user, PaymentSchedule $schedule, string $amount): void
    {
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => $amount,
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);
    }

    public function test_financials_endpoint_and_project_show_financials_block_return_matching_values(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization, ['grand_total' => '50000.00']);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '50000.00',
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'amount' => '20000.00',
            'percentage' => null,
            'status' => 'pending',
        ]);
        $user = $this->fullAccessUser($organization);
        $this->recordPayment($user, $schedule, '15000.00');

        $financials = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $show = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200);

        $this->assertSame('15000.00', $financials->json('data.collected'));
        $this->assertSame('35000.00', $financials->json('data.outstanding')); // 50000 - 15000
        $this->assertSame($financials->json('data.collected'), $show->json('data.financials.collected'));
        $this->assertSame($financials->json('data.outstanding'), $show->json('data.financials.outstanding'));
        $this->assertSame($financials->json('data.value'), $show->json('data.financials.value'));
    }

    public function test_collected_sums_payments_across_multiple_schedules_on_the_same_contract(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '100000.00',
        ]);
        $scheduleA = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id, 'sequence_no' => 1, 'amount' => '40000.00', 'percentage' => null,
        ]);
        $scheduleB = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id, 'sequence_no' => 2, 'amount' => '60000.00', 'percentage' => null,
        ]);
        $user = $this->fullAccessUser($organization);

        $this->recordPayment($user, $scheduleA, '40000.00'); // fully pays schedule A
        $this->recordPayment($user, $scheduleB, '10000.00'); // partial on schedule B

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $this->assertSame('50000.00', $response->json('data.collected'));
        $this->assertSame('50000.00', $response->json('data.outstanding')); // 100000 - 50000
    }

    public function test_outstanding_is_zero_when_the_project_has_no_contract_yet_even_if_priced(): void
    {
        $organization = Organization::factory()->create();
        // Priced (grand_total set) but no contract at all — resolveOutstanding() must return 0,
        // NOT the priced value, per its documented "receivable, not an estimate" reasoning.
        $project = $this->projectIn($organization, ['grand_total' => '75000.00']);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $this->assertSame('75000.00', $response->json('data.value'));
        $this->assertSame(0, $response->json('data.collected'));
        $this->assertSame(0, $response->json('data.outstanding'));
    }

    public function test_outstanding_clamps_at_zero_on_overpayment_never_goes_negative(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '10000.00',
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id, 'sequence_no' => 1, 'amount' => '10000.00', 'percentage' => null,
        ]);
        $user = $this->fullAccessUser($organization);

        $this->recordPayment($user, $schedule, '12000.00'); // overpay

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $this->assertSame('12000.00', $response->json('data.collected'));
        $this->assertSame(0, $response->json('data.outstanding')); // clamped, never negative
    }

    public function test_actual_cost_and_gross_profit_remain_the_documented_placeholders(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization, ['grand_total' => '50000.00']);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        $this->assertSame(0, $response->json('data.actual_cost'));
        $this->assertNull($response->json('data.gross_profit'));

        $show = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200);
        $this->assertSame(0, $show->json('data.financials.actual_cost'));
        $this->assertNull($show->json('data.financials.gross_profit'));
    }
}
