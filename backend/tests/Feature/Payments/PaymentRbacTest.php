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
 * PROJECT_CONTEXT.md Sprint 6 -> RBAC split for the payments surface. This is a DIFFERENT
 * permission posture than every prior sprint's commercial-workflow tests (BoqRbacTest,
 * PricingRbacTest (if any), ProposalRbacTest, ContractRbacTest): those all gate only writes
 * behind Permissions::MANAGE_BOQ and let READS through on active membership alone. Sprint 6 is
 * the first surface where reads themselves are gated — behind Permissions::VIEW_FINANCIALS, a
 * SEPARATE permission from MANAGE_BOQ — per PaymentScheduleController/PaymentController/
 * ProjectFinancialsController's docblocks. Concretely:
 *
 *   - index (schedules), index (payments), receipt, financials -> require VIEW_FINANCIALS.
 *   - store (schedules), store (payments) -> require MANAGE_BOQ.
 *
 * Per the seeded RoleSeeder, Designer has MANAGE_BOQ=true but VIEW_FINANCIALS=false — exactly
 * the "can record a payment but can't see the numbers" split the locked product decision wants.
 * Both halves of the split are verified independently below, since a role having one permission
 * says nothing about the other.
 */
class PaymentRbacTest extends TestCase
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

    /** @return array{0: Project, 1: Contract, 2: PaymentSchedule} */
    private function setUpContractAndSchedule(Organization $organization): array
    {
        $project = $this->projectIn($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '100000.00',
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'amount' => '1000.00',
            'percentage' => null,
        ]);

        return [$project, $contract, $schedule];
    }

    /**
     * Designer-shaped role: can write (create schedules, record payments) but genuinely cannot
     * read any of the four VIEW_FINANCIALS-gated endpoints. This is the split PROJECT_CONTEXT.md
     * calls out as new this sprint — every read-gate must independently 403, not just some.
     */
    public function test_manage_boq_without_view_financials_can_write_but_gets_403_on_every_read_endpoint(): void
    {
        $organization = Organization::factory()->create();
        [$project, $contract, $schedule] = $this->setUpContractAndSchedule($organization);
        $user = $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => false,
        ]);
        $headers = $this->authHeader($user);

        // --- Writes succeed ---
        $createSchedule = $this->withHeaders($headers)
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit', 'sequence_no' => 2, 'due_date' => '2026-11-01', 'amount' => 500,
            ]);
        $createSchedule->assertStatus(201);

        $payment = $this->withHeaders($headers)
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '400.00', 'payment_method' => 'bank_transfer', 'paid_at' => now()->toDateTimeString(),
            ]);
        $payment->assertStatus(201);
        $paymentId = $payment->json('data.id');

        // --- Reads are forbidden, every single one ---
        $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->getJson("/api/v1/payment-schedules/{$schedule->id}/payments")
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->getJson("/api/v1/payments/{$paymentId}/receipt")
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(403);
    }

    /**
     * Inverse split: a role that can see the numbers but cannot mutate them (e.g. an
     * accountant/bookkeeper persona) — every read succeeds, every write 403s.
     */
    public function test_view_financials_without_manage_boq_can_read_but_gets_403_on_every_write_endpoint(): void
    {
        $organization = Organization::factory()->create();
        [$project, $contract, $schedule] = $this->setUpContractAndSchedule($organization);
        $user = $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => false,
            Permissions::VIEW_FINANCIALS => true,
        ]);
        $headers = $this->authHeader($user);

        // --- Reads succeed ---
        $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson("/api/v1/payment-schedules/{$schedule->id}/payments")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);

        // --- Writes are forbidden ---
        $this->withHeaders($headers)
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit', 'sequence_no' => 2, 'due_date' => '2026-11-01', 'amount' => 500,
            ])
            ->assertStatus(403);
        $this->assertDatabaseCount('payment_schedules', 1); // only the pre-seeded one

        $this->withHeaders($headers)
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '400.00', 'payment_method' => 'bank_transfer', 'paid_at' => now()->toDateTimeString(),
            ])
            ->assertStatus(403);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_role_with_neither_permission_is_forbidden_from_every_endpoint(): void
    {
        $organization = Organization::factory()->create();
        [$project, $contract, $schedule] = $this->setUpContractAndSchedule($organization);
        $user = $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => false,
            Permissions::VIEW_FINANCIALS => false,
        ]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit', 'sequence_no' => 2, 'due_date' => '2026-11-01', 'amount' => 500,
            ])
            ->assertStatus(403);

        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(403);
    }

    public function test_a_role_with_both_permissions_can_read_and_write_everything(): void
    {
        $organization = Organization::factory()->create();
        [$project, $contract, $schedule] = $this->setUpContractAndSchedule($organization);
        $user = $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit', 'sequence_no' => 2, 'due_date' => '2026-11-01', 'amount' => 500,
            ])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '400.00', 'payment_method' => 'bank_transfer', 'paid_at' => now()->toDateTimeString(),
            ])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson("/api/v1/projects/{$project->id}/financials")
            ->assertStatus(200);
    }

    public function test_unauthenticated_requests_to_every_payments_route_are_rejected(): void
    {
        $organization = Organization::factory()->create();
        [$project, $contract, $schedule] = $this->setUpContractAndSchedule($organization);

        $this->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")->assertStatus(401);
        $this->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [])->assertStatus(401);
        $this->getJson("/api/v1/payment-schedules/{$schedule->id}/payments")->assertStatus(401);
        $this->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [])->assertStatus(401);
        $this->getJson("/api/v1/projects/{$project->id}/financials")->assertStatus(401);
    }
}
