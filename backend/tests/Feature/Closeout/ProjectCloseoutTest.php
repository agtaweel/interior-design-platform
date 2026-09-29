<?php

namespace Tests\Feature\Closeout;

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
 * BRD v3 §12 "Reconciliation & Closeout" (ProjectCloseoutService, ProjectCloseoutController).
 */
class ProjectCloseoutTest extends TestCase
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

    private function ownerUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => true,
            Permissions::MANAGE_FINANCIAL_CLOSEOUT => true,
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

    private function completedProject(Organization $organization): Project
    {
        return $this->projectIn($organization, ['status' => 'completed']);
    }

    public function test_start_requires_the_project_to_be_operationally_completed(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization, ['status' => 'active']);
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROJECT_NOT_COMPLETED');
    }

    public function test_start_transitions_active_to_financial_pending(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start");

        $response->assertStatus(200);
        $this->assertSame('financial_pending', $response->json('data.closeout.financial_status'));
    }

    public function test_starting_twice_fails_with_409(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(200);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLOSEOUT_NOT_ACTIVE');
    }

    public function test_check_reports_not_ready_when_outstanding_balance_remains(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'grand_total' => '10000.00',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/check");

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.ready'));
        $this->assertSame('10000.00', $response->json('data.outstanding'));
        $this->assertSame('financial_pending', $response->json('data.financial_status'));
    }

    public function test_check_transitions_to_ready_for_close_once_fully_paid(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'grand_total' => '10000.00',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        $contract = Contract::query()->where('project_id', $project->id)->firstOrFail();
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'amount' => '10000.00',
            'percentage' => null,
            'status' => 'pending',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '10000.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/check");

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.ready'));
        $this->assertSame(0, $response->json('data.outstanding'));
        $this->assertSame('ready_for_close', $response->json('data.financial_status'));
    }

    public function test_close_fails_409_when_not_ready(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(200);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/close")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLOSEOUT_NOT_READY');
    }

    public function test_close_succeeds_once_ready_and_sets_closed_at_and_closed_by(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization);

        // No contract at all: obligation stays 0, so checkReadiness sees outstanding=0
        // immediately — a valid (if unusual) "nothing was ever owed" reconciled state.
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/start")
            ->assertStatus(200);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/check")
            ->assertStatus(200)
            ->assertJsonPath('data.ready', true);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/close");

        $response->assertStatus(200);
        $this->assertSame('closed', $response->json('data.closeout.financial_status'));
        $this->assertNotNull($response->json('data.closeout.financial_closed_at'));
        $this->assertFalse($response->json('data.closeout.force_closed'));
    }

    public function test_force_close_requires_manage_financial_closeout_permission_not_just_manage_boq(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->fullAccessUser($organization); // MANAGE_BOQ only, no MANAGE_FINANCIAL_CLOSEOUT

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/force-close", ['reason' => 'Client went bankrupt'])
            ->assertStatus(403);
    }

    public function test_force_close_requires_a_reason(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->ownerUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/force-close", [])
            ->assertStatus(422);
    }

    public function test_force_close_jumps_directly_to_closed_from_active_bypassing_outstanding_check(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->ownerUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'grand_total' => '10000.00',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        // No payment recorded at all — outstanding is 10000.00, would block a normal close.

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/force-close", ['reason' => 'Client went bankrupt, writing off balance']);

        $response->assertStatus(200);
        $this->assertSame('closed', $response->json('data.closeout.financial_status'));
        $this->assertTrue($response->json('data.closeout.force_closed'));
        $this->assertSame('Client went bankrupt, writing off balance', $response->json('data.closeout.force_close_reason'));
    }

    public function test_force_closing_an_already_closed_project_fails_409(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->ownerUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/force-close", ['reason' => 'first'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/force-close", ['reason' => 'second'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CLOSEOUT_ALREADY_CLOSED');
    }

    public function test_force_close_leaves_an_audit_trail(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->completedProject($organization);
        $user = $this->ownerUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/closeout/force-close", ['reason' => 'Write-off'])
            ->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'Project',
            'entity_id' => $project->id,
            'action' => 'updated',
        ]);
    }
}
