<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\Approval;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 7 -> POST /public/change-orders/{token}/approve: mirrors Sprint 4's
 * ProposalApprovalTest exactly (same OtpChallengeService, same Idempotency-Key reconciliation,
 * same "already approved" 409 posture) — this is the SECOND real consumer of that generic
 * mechanism and it must behave identically, not almost-identically.
 */
class ChangeOrderApprovalIdempotencyTest extends TestCase
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

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    private function seedBoqItem(Project $project): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
    }

    /**
     * @return array{0: int, 1: string, 2: string} [changeOrderId, token, real otpCode]
     */
    private function createAndSendChangeOrder(array $headers, Project $project): array
    {
        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/change-orders", [
                'reason' => 'Client requested a tile upgrade.',
                'items' => [
                    ['action' => 'add', 'description' => 'Upgraded Tile', 'quantity' => 2, 'unit' => 'm2', 'new_unit_price' => 500],
                ],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/change-orders/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        return [$id, $token, $send->json('data.otp_code')];
    }

    private function approve(string $token, array $body, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson("/api/v1/public/change-orders/{$token}/approve", $body);
    }

    private function setUpSentChangeOrder(): array
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);

        return $this->createAndSendChangeOrder($this->authHeader($user), $project);
    }

    // --- Idempotency: fresh key succeeds and is stored ---

    public function test_approving_with_a_fresh_idempotency_key_succeeds_and_stores_the_response(): void
    {
        [$id, $token, $otp] = $this->setUpSentChangeOrder();

        $this->assertDatabaseCount('idempotency_keys', 0);

        $response = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'idem-key-1']
        );

        $response->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ChangeOrder::find($id)->status);

        $stored = IdempotencyKey::first();
        $this->assertNotNull($stored);
        $this->assertSame("change_order_approve:{$id}", $stored->scope);
        $this->assertSame('idem-key-1', $stored->key);
        $this->assertSame(200, $stored->response_status);
        $this->assertSame('approved', $stored->response_body['status']);
    }

    // --- Idempotency: replay with the SAME key returns the exact original response ---

    public function test_replaying_the_same_idempotency_key_returns_the_exact_original_response_verbatim(): void
    {
        [, $token, $otp] = $this->setUpSentChangeOrder();

        $first = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'idem-key-2']
        )->assertStatus(200);

        $originalBody = $first->json();

        // Replay with the SAME key but a garbage body — must NOT re-check OTP at all.
        $replay = $this->approve(
            $token,
            ['name' => 'Someone Else', 'otp' => 'not-even-numeric-garbage'],
            ['Idempotency-Key' => 'idem-key-2']
        );

        $replay->assertStatus(200);
        $this->assertSame($originalBody, $replay->json());

        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    // --- Idempotency: NO/different key after already approved -> 409 ---

    public function test_approving_again_without_idempotency_key_after_already_approved_returns_409(): void
    {
        [$id, $token, $otp] = $this->setUpSentChangeOrder();

        $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp])->assertStatus(200);
        $this->assertSame('approved', ChangeOrder::find($id)->status);
        $this->assertDatabaseCount('idempotency_keys', 0);

        $second = $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp]);

        $second->assertStatus(409)->assertJsonPath('error.code', 'CHANGE_ORDER_ALREADY_APPROVED');
        $this->assertDatabaseCount('approvals', 1);
    }

    public function test_a_never_before_seen_idempotency_key_against_an_already_approved_change_order_still_conflicts(): void
    {
        [, $token, $otp] = $this->setUpSentChangeOrder();

        $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'first-key']
        )->assertStatus(200);

        $second = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'a-totally-different-never-used-key']
        );

        $second->assertStatus(409)->assertJsonPath('error.code', 'CHANGE_ORDER_ALREADY_APPROVED');
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_a_failed_attempt_with_an_idempotency_key_is_not_stored_so_a_retry_can_still_succeed(): void
    {
        [$id, $token, $otp] = $this->setUpSentChangeOrder();

        $failed = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => '000000'],
            ['Idempotency-Key' => 'retry-key']
        );
        $failed->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
        $this->assertDatabaseCount('idempotency_keys', 0);

        $retry = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'retry-key']
        );
        $retry->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ChangeOrder::find($id)->status);
    }

    // --- Approval side effects ---

    public function test_approval_creates_an_approvals_row_with_entity_type_change_order(): void
    {
        [$id, $token, $otp] = $this->setUpSentChangeOrder();
        $project = ChangeOrder::find($id)->project;

        $this->approve($token, ['name' => 'Jane Client', 'comment' => 'Approved, go ahead', 'otp' => $otp])
            ->assertStatus(200);

        $approval = Approval::first();
        $this->assertNotNull($approval);
        $this->assertSame($project->organization_id, $approval->organization_id);
        $this->assertSame($project->id, $approval->project_id);
        $this->assertSame(ChangeOrder::ENTITY_TYPE, $approval->entity_type);
        $this->assertSame('change_order', $approval->entity_type);
        $this->assertSame($id, $approval->entity_id);
        $this->assertSame('client', $approval->approver_type);
        $this->assertNull($approval->user_id);
        $this->assertSame('approved', $approval->status);
        $this->assertNotNull($approval->approved_at);
        $this->assertNotNull($approval->ip_address);
        $this->assertStringContainsString('Jane Client', $approval->comment);
    }

    public function test_approval_sets_approved_at_and_marks_the_otp_challenge_verified(): void
    {
        [$id, $token, $otp] = $this->setUpSentChangeOrder();

        $this->assertNull(OtpChallenge::first()->verified_at);

        $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp])->assertStatus(200);

        $changeOrder = ChangeOrder::find($id);
        $this->assertSame('approved', $changeOrder->status);
        $this->assertNotNull($changeOrder->approved_at);

        $this->assertNotNull(OtpChallenge::first()->verified_at);
    }

    // --- OTP verification / lockout (mirrors Sprint 4's OtpVerificationTest) ---

    public function test_correct_otp_approves_the_change_order(): void
    {
        [$id, $token, $realOtp] = $this->setUpSentChangeOrder();

        $this->approve($token, ['name' => 'Jane Client', 'otp' => $realOtp])
            ->assertStatus(200)
            ->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ChangeOrder::find($id)->status);
    }

    public function test_wrong_otp_returns_422_otp_invalid_with_attempts_remaining_and_increments_attempts(): void
    {
        [, $token] = $this->setUpSentChangeOrder();

        $response = $this->approve($token, ['name' => 'Jane Client', 'otp' => '000000']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'OTP_INVALID')
            ->assertJsonPath('error.details.attempts_remaining', 4);

        $challenge = OtpChallenge::first();
        $this->assertSame(1, $challenge->attempts);
        $this->assertNull($challenge->verified_at);
        $this->assertSame('sent', ChangeOrder::first()->status);
    }

    public function test_five_failed_attempts_locks_out_and_returns_422_otp_locked_same_cap_as_proposals(): void
    {
        // OtpChallengeService::MAX_ATTEMPTS = 5 — reused verbatim from the proposal flow.
        [, $token, $realOtp] = $this->setUpSentChangeOrder();

        for ($i = 0; $i < 5; $i++) {
            $this->approve($token, ['name' => 'Jane Client', 'otp' => '000000'])->assertStatus(422);
        }

        $this->assertSame(5, OtpChallenge::first()->attempts);

        $lockedWithWrongCode = $this->approve($token, ['name' => 'Jane Client', 'otp' => '111111']);
        $lockedWithWrongCode->assertStatus(422)->assertJsonPath('error.code', 'OTP_LOCKED');

        // Even the genuinely correct code is now rejected — lockout is absolute.
        $lockedWithRightCode = $this->approve($token, ['name' => 'Jane Client', 'otp' => $realOtp]);
        $lockedWithRightCode->assertStatus(422)->assertJsonPath('error.code', 'OTP_LOCKED');
        $this->assertSame('sent', ChangeOrder::first()->status);
    }

    public function test_expired_otp_is_rejected_distinctly_as_otp_expired(): void
    {
        [, $token, $realOtp] = $this->setUpSentChangeOrder();

        OtpChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $response = $this->approve($token, ['name' => 'Jane Client', 'otp' => $realOtp]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'OTP_EXPIRED');
        $this->assertSame('sent', ChangeOrder::first()->status);
    }
}
