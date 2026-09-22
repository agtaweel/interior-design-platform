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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 6 -> payment_schedules status semantics
 * (PaymentRecordingService::refreshScheduleStatus(), PaymentSchedule::isOverdue()/isUpcoming()).
 * This is "the trickiest part" per the task brief, so each sub-behavior is pinned in its own
 * test rather than folded together:
 *
 *   - a schedule stays 'pending' after a partial payment (cumulative < amount);
 *   - it flips to 'paid' once cumulative payments reach the amount EXACTLY;
 *   - it also flips to 'paid' on overpayment (cumulative > amount) — refreshScheduleStatus()
 *     uses bccomp(...) >= 0, not an exact match;
 *   - is_overdue/is_upcoming are computed relative to due_date AND status — critically, a 'paid'
 *     schedule must never read as overdue even if its due_date is in the past, since both
 *     isOverdue()/isUpcoming() gate on `status === 'pending'` first.
 */
class PaymentStatusTest extends TestCase
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

    /** @return array{0: PaymentSchedule, 1: User} a pending, non-overdue schedule of amount 1000.00 */
    private function setUpSchedule(?string $dueDate = null): array
    {
        $organization = Organization::factory()->create();
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
            'due_date' => $dueDate ?? now()->addMonth()->toDateString(),
            'percentage' => null,
            'amount' => '1000.00',
            'status' => 'pending',
        ]);
        $user = $this->fullAccessUser($organization);

        return [$schedule, $user];
    }

    private function recordPayment(User $user, PaymentSchedule $schedule, string $amount): TestResponse
    {
        return $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => $amount,
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ]);
    }

    private function fetchSchedule(User $user, Contract $contract, int $scheduleId): array
    {
        $index = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")
            ->assertStatus(200);

        $rows = collect($index->json('data'));
        $row = $rows->firstWhere('id', $scheduleId);
        $this->assertNotNull($row, 'schedule not found in index response');

        return $row;
    }

    public function test_schedule_stays_pending_after_a_partial_payment(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->recordPayment($user, $schedule, '400.00')->assertStatus(201);

        $this->assertSame('pending', $schedule->fresh()->status);

        $row = $this->fetchSchedule($user, $schedule->contract, $schedule->id);
        $this->assertSame('pending', $row['status']);
        $this->assertFalse($row['is_paid']);
    }

    public function test_schedule_flips_to_paid_when_cumulative_payments_exactly_match_amount(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->recordPayment($user, $schedule, '400.00')->assertStatus(201);
        $this->assertSame('pending', $schedule->fresh()->status);

        $this->recordPayment($user, $schedule, '600.00')->assertStatus(201);
        $this->assertSame('paid', $schedule->fresh()->status);

        $row = $this->fetchSchedule($user, $schedule->contract, $schedule->id);
        $this->assertSame('paid', $row['status']);
        $this->assertTrue($row['is_paid']);
    }

    public function test_schedule_flips_to_paid_on_overpayment_cumulative_total_exceeds_amount(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->recordPayment($user, $schedule, '400.00')->assertStatus(201);
        $this->recordPayment($user, $schedule, '900.00')->assertStatus(201); // cumulative 1300 > 1000

        $this->assertSame('paid', $schedule->fresh()->status);
        $this->assertDatabaseCount('payments', 2);

        $row = $this->fetchSchedule($user, $schedule->contract, $schedule->id);
        $this->assertSame('paid', $row['status']);
        $this->assertTrue($row['is_paid']);
    }

    public function test_a_single_payment_that_meets_the_full_amount_immediately_flips_to_paid(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->recordPayment($user, $schedule, '1000.00')->assertStatus(201);

        $this->assertSame('paid', $schedule->fresh()->status);
    }

    public function test_is_overdue_true_for_a_pending_schedule_whose_due_date_has_passed(): void
    {
        [$schedule, $user] = $this->setUpSchedule(now()->subDays(5)->toDateString());

        $row = $this->fetchSchedule($user, $schedule->contract, $schedule->id);
        $this->assertSame('pending', $row['status']);
        $this->assertTrue($row['is_overdue']);
        $this->assertFalse($row['is_upcoming']);
        $this->assertFalse($row['is_paid']);
    }

    public function test_is_upcoming_true_for_a_pending_schedule_whose_due_date_is_in_the_future(): void
    {
        [$schedule, $user] = $this->setUpSchedule(now()->addDays(10)->toDateString());

        $row = $this->fetchSchedule($user, $schedule->contract, $schedule->id);
        $this->assertSame('pending', $row['status']);
        $this->assertFalse($row['is_overdue']);
        $this->assertTrue($row['is_upcoming']);
        $this->assertFalse($row['is_paid']);
    }

    /**
     * The critical "easy to get backwards" case per the task brief: a schedule that has been
     * fully paid must NEVER show as overdue, even though its due_date is in the past — both
     * isOverdue() and isUpcoming() gate on `status === 'pending'` first, so a 'paid' row must
     * read is_overdue=false AND is_upcoming=false simultaneously (neither applies once paid).
     */
    public function test_a_paid_schedule_never_shows_as_overdue_even_when_its_due_date_is_in_the_past(): void
    {
        [$schedule, $user] = $this->setUpSchedule(now()->subMonth()->toDateString());

        $this->recordPayment($user, $schedule, '1000.00')->assertStatus(201);
        $this->assertSame('paid', $schedule->fresh()->status);

        $row = $this->fetchSchedule($user, $schedule->contract, $schedule->id);
        $this->assertSame('paid', $row['status']);
        $this->assertFalse($row['is_overdue']);
        $this->assertFalse($row['is_upcoming']);
        $this->assertTrue($row['is_paid']);
    }

    /**
     * A schedule already 'paid' must never flip back to 'pending', and additional payments
     * recorded against it (e.g. correcting an overpayment) must not disturb its status —
     * refreshScheduleStatus() short-circuits immediately once status is already 'paid'.
     */
    public function test_status_never_flips_back_to_pending_after_an_additional_payment_against_an_already_paid_schedule(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->recordPayment($user, $schedule, '1000.00')->assertStatus(201);
        $this->assertSame('paid', $schedule->fresh()->status);

        $this->recordPayment($user, $schedule, '50.00')->assertStatus(201);
        $this->assertSame('paid', $schedule->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
    }
}
