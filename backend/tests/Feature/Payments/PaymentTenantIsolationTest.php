<?php

namespace Tests\Feature\Payments;

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
 * Extends the tenant-isolation guarantee (tests/Feature/Tenancy/TenantIsolationTest.php and its
 * per-sprint descendants) to Sprint 6's payments surface. Two DIFFERENT scoping mechanisms are
 * in play here and each is verified independently, per the task's explicit instruction not to
 * assume the three-hop case is safe just because Sprint 4's two-hop proposal_items case was:
 *
 *   - payment_schedules: THREE-HOP indirect (contract_id -> contracts.project_id ->
 *     projects.organization_id) — resolveTenantScopedContract()/resolveTenantScopedSchedule()
 *     re-derive the owning organization manually; there is no organization_id column at all to
 *     rely on a global scope for.
 *   - payments: DIRECT organization_id/project_id columns via BelongsToOrganization, exactly
 *     like Client/Project — Payment::find() is protected by the OrganizationScope global scope
 *     automatically, no manual re-resolution needed in the controller.
 */
class PaymentTenantIsolationTest extends TestCase
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

    /** @return array{0: Contract, 1: PaymentSchedule} a contract + schedule owned by $organization */
    private function contractWithScheduleIn(Organization $organization, string $scheduleName = 'Org Secret Schedule'): array
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '100000.00',
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'name' => $scheduleName,
            'amount' => '1000.00',
            'percentage' => null,
        ]);

        return [$contract, $schedule];
    }

    // --- payment_schedules: three-hop indirect scoping ---

    public function test_cannot_list_another_organizations_contract_payment_schedules(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        [$contractB] = $this->contractWithScheduleIn($orgB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/contracts/{$contractB->id}/payment-schedules")
            ->assertStatus(404);
    }

    public function test_cannot_create_a_payment_schedule_for_another_organizations_contract(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        [$contractB] = $this->contractWithScheduleIn($orgB);

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/contracts/{$contractB->id}/payment-schedules", [
                'name' => 'Hostile Schedule', 'sequence_no' => 99, 'due_date' => '2027-01-01', 'amount' => 500,
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('payment_schedules', 1); // only org B's pre-seeded one
    }

    public function test_cannot_list_payments_against_another_organizations_payment_schedule_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        [, $scheduleB] = $this->contractWithScheduleIn($orgB);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/payment-schedules/{$scheduleB->id}/payments")
            ->assertStatus(404);
    }

    public function test_cannot_record_a_payment_against_another_organizations_payment_schedule_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        [, $scheduleB] = $this->contractWithScheduleIn($orgB);

        $response = $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/payment-schedules/{$scheduleB->id}/payments", [
                'amount' => '500.00', 'payment_method' => 'cash', 'paid_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('pending', $scheduleB->fresh()->status);
    }

    // --- payments: direct organization_id/project_id scoping ---

    public function test_cannot_view_another_organizations_payment_receipt_by_guessing_the_payment_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $userB = $this->fullAccessUser($orgB);
        [, $scheduleB] = $this->contractWithScheduleIn($orgB);

        $paymentB = $this->withHeaders($this->authHeader($userB))
            ->postJson("/api/v1/payment-schedules/{$scheduleB->id}/payments", [
                'amount' => '500.00', 'payment_method' => 'cash', 'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201)->json('data.id');

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/payments/{$paymentB}/receipt")
            ->assertStatus(404);
    }

    public function test_org_a_sees_only_its_own_schedules_and_payments_when_both_orgs_have_them(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        [$contractA, $scheduleA] = $this->contractWithScheduleIn($orgA, 'Org A Schedule');
        [, $scheduleB] = $this->contractWithScheduleIn($orgB, 'Org B Schedule');

        $this->withHeaders($this->authHeader($userA))
            ->postJson("/api/v1/payment-schedules/{$scheduleA->id}/payments", [
                'amount' => '500.00', 'payment_method' => 'cash', 'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);

        $index = $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/contracts/{$contractA->id}/payment-schedules")
            ->assertStatus(200);

        $this->assertCount(1, $index->json('data'));
        $this->assertSame('Org A Schedule', $index->json('data.0.name'));
        $this->assertDatabaseCount('payment_schedules', 2); // one per org, total
        $this->assertDatabaseCount('payments', 1); // only org A's payment was ever created
        $this->assertNotSame($scheduleA->id, $scheduleB->id);
    }

    public function test_cannot_view_another_organizations_project_financials_by_guessing_its_id(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        $projectB = Project::factory()->create(['organization_id' => $orgB->id, 'client_id' => $clientB->id]);

        $this->withHeaders($this->authHeader($userA))
            ->getJson("/api/v1/projects/{$projectB->id}/financials")
            ->assertStatus(404);
    }
}
