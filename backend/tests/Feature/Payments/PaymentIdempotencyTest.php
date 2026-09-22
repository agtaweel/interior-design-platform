<?php

namespace Tests\Feature\Payments;

use App\Models\Client;
use App\Models\Contract;
use App\Models\IdempotencyKey;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 6 -> POST /payment-schedules/{id}/payments idempotency
 * (PaymentController::store()'s docblock: "implement the EXACT same replay semantics as Sprint
 * 4's proposal-approval endpoint" — mirrors tests/Feature/Proposals/ProposalApprovalTest.php's
 * rigor point-for-point for the fresh-key/replay/validation-boundary/failed-attempt behaviors).
 *
 * IMPORTANT DEVIATION FROM SPRINT 4, verified against the actual implementation rather than
 * assumed: proposal-approval has a single terminal state ("already approved") that a
 * no-key/different-key request bumps into as a 409 conflict. Payments have NO such terminal
 * state — PaymentController::store() never checks whether the schedule is already 'paid' before
 * recording a new payment; multiple payments against one schedule (including against an
 * already-'paid' one, e.g. correcting/topping-up) are normal and always succeed with 201. So
 * unlike ProposalApprovalTest::test_approving_again_without_idempotency_key_after_already_approved_returns_409(),
 * there is no equivalent "second payment without a key after already paid -> 409" test here:
 * that isn't a bug, it's confirmed intended behavior for this different domain (see
 * test_a_second_payment_without_any_idempotency_key_against_an_already_paid_schedule_is_not_blocked
 * below, which pins the actual — different — behavior explicitly rather than silently omitting
 * the case).
 */
class PaymentIdempotencyTest extends TestCase
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

    /** @return array{0: PaymentSchedule, 1: User} a pending schedule of amount 1000.00 */
    private function setUpSchedule(): array
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
            'due_date' => now()->addMonth()->toDateString(),
            'percentage' => null,
            'amount' => '1000.00',
            'status' => 'pending',
        ]);
        $user = $this->fullAccessUser($organization);

        return [$schedule, $user];
    }

    private function pay(PaymentSchedule $schedule, User $user, array $body, array $headers = []): TestResponse
    {
        $headers = array_merge($this->authHeader($user), $headers);

        return $this->withHeaders($headers)->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", $body);
    }

    private function validBody(): array
    {
        return [
            'amount' => '400.00',
            'payment_method' => 'bank_transfer',
            'paid_at' => now()->toDateTimeString(),
            'reference' => 'REF-0001',
        ];
    }

    // --- Fresh key succeeds and is stored ---

    public function test_recording_a_payment_with_a_fresh_idempotency_key_succeeds_and_stores_the_response(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->assertDatabaseCount('idempotency_keys', 0);

        $response = $this->pay($schedule, $user, $this->validBody(), ['Idempotency-Key' => 'pay-key-1']);

        $response->assertStatus(201);
        $this->assertSame('400.00', $response->json('data.amount'));
        $this->assertDatabaseCount('payments', 1);

        $stored = IdempotencyKey::first();
        $this->assertNotNull($stored);
        $this->assertSame("payment_create:{$schedule->id}", $stored->scope);
        $this->assertSame('pay-key-1', $stored->key);
        $this->assertSame(201, $stored->response_status);
        $this->assertSame('400.00', $stored->response_body['data']['amount']);
    }

    // --- Replay with the SAME key: exact original response, no second payment row, no re-run of status-flip logic ---

    public function test_replaying_the_same_idempotency_key_returns_the_exact_original_response_and_creates_no_second_payment(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $first = $this->pay($schedule, $user, $this->validBody(), ['Idempotency-Key' => 'pay-key-2'])
            ->assertStatus(201);
        $originalBody = $first->json();

        // Replay with the SAME key but a different (still validation-passing) body: different
        // amount, different method, different reference. Per the documented reconciliation this
        // must NOT re-validate or re-process the new body — it must return the exact stored
        // response, byte for byte.
        $replay = $this->pay($schedule, $user, [
            'amount' => '999.00',
            'payment_method' => 'cash',
            'paid_at' => now()->addDay()->toDateTimeString(),
            'reference' => 'SOMETHING-ELSE',
        ], ['Idempotency-Key' => 'pay-key-2']);

        $replay->assertStatus(201);
        $this->assertSame($originalBody, $replay->json());

        // Exactly one payment row was ever created; the schedule's cumulative-paid status-flip
        // logic ran exactly once too (not re-triggered by the replay).
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertSame('400.00', (string) Payment::first()->amount);
        $this->assertSame('pending', $schedule->fresh()->status); // 400 < 1000, still pending
    }

    /**
     * Exact boundary Sprint 4 pins for the same reason (ProposalApprovalTest::
     * test_replay_does_not_require_a_body_at_all...): Laravel's FormRequest validation runs
     * BEFORE the controller body, so a replay with a body that fails StorePaymentRequest's rules
     * outright (missing required fields) still 422s — the replay short-circuit inside
     * PaymentController::store() never gets a chance to run. This is the documented, deliberately
     * mirrored boundary, not a gap in the replay mechanism.
     */
    public function test_replay_with_a_body_that_fails_validation_still_422s_before_reaching_the_replay_check(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $first = $this->pay($schedule, $user, $this->validBody(), ['Idempotency-Key' => 'pay-key-3'])
            ->assertStatus(201);

        // Completely empty body — amount/payment_method/paid_at are all required.
        $replay = $this->pay($schedule, $user, [], ['Idempotency-Key' => 'pay-key-3']);

        $replay->assertStatus(422);
        $this->assertSame('validation_failed', $replay->json('error.code'));

        // The original successful call is untouched, and no second payment/key row was created.
        $this->assertSame('400.00', $first->json('data.amount'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_a_never_before_seen_idempotency_key_records_a_genuinely_new_payment(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->pay($schedule, $user, $this->validBody(), ['Idempotency-Key' => 'first-key'])
            ->assertStatus(201);

        $second = $this->pay($schedule, $user, [
            'amount' => '200.00',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
        ], ['Idempotency-Key' => 'a-totally-different-never-used-key']);

        $second->assertStatus(201);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_a_failed_attempt_with_an_idempotency_key_is_not_stored_so_a_retry_can_still_succeed(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        // Missing payment_method -> 422, WITH an Idempotency-Key header. Must not poison the
        // idempotency store, since only successful mutations are recorded (same rule Sprint 4
        // established for proposal approval).
        $failed = $this->pay($schedule, $user, [
            'amount' => '400.00',
            'paid_at' => now()->toDateTimeString(),
        ], ['Idempotency-Key' => 'retry-key']);
        $failed->assertStatus(422);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('payments', 0);

        // Retrying with the SAME key but now a complete, valid body must succeed.
        $retry = $this->pay($schedule, $user, $this->validBody(), ['Idempotency-Key' => 'retry-key']);
        $retry->assertStatus(201);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    // --- The documented deviation from Sprint 4: no terminal "already paid" conflict state ---

    /**
     * Unlike proposal-approval's single terminal "already approved" state (which 409s a
     * no-key/different-key retry), payment_schedules going 'paid' is NOT a terminal state that
     * blocks further POSTs — PaymentController::store() and PaymentRecordingService::record()
     * never check the schedule's current status before accepting and recording a new payment.
     * This is verified here explicitly rather than assumed: a second, legitimate payment
     * attempt (no Idempotency-Key header at all) against an already-fully-paid schedule must
     * succeed with 201, not 409.
     */
    public function test_a_second_payment_without_any_idempotency_key_against_an_already_paid_schedule_is_not_blocked(): void
    {
        [$schedule, $user] = $this->setUpSchedule();

        $this->pay($schedule, $user, [
            'amount' => '1000.00',
            'payment_method' => 'bank_transfer',
            'paid_at' => now()->toDateTimeString(),
        ])->assertStatus(201);
        $this->assertSame('paid', $schedule->fresh()->status);

        // No Idempotency-Key header at all on this second call.
        $second = $this->pay($schedule, $user, [
            'amount' => '50.00',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
        ]);

        $second->assertStatus(201);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('paid', $schedule->fresh()->status);
    }
}
