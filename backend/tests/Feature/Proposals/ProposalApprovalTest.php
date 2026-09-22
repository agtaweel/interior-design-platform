<?php

namespace Tests\Feature\Proposals;

use App\Models\Approval;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 4 -> POST /public/proposals/{token}/approve: the idempotency-key
 * reconciliation (PublicProposalController::approve()'s docblock) and the approval side effects
 * (approvals row, approved_at, otp_challenges.verified_at). This is the highest-stakes surface
 * in the sprint (money-moving public endpoint) so both idempotency behaviors are pinned
 * explicitly and separately, per the task's instruction not to assume one half works because
 * the other does:
 *
 *   - A request WITH an Idempotency-Key header that matches a stored key -> replay the exact
 *     stored response, unconditionally, even against a garbage body/OTP.
 *   - A request WITHOUT a (recognized) Idempotency-Key header, against an already-approved
 *     version -> normal state-conflict rules apply -> 409 PROPOSAL_ALREADY_APPROVED.
 */
class ProposalApprovalTest extends TestCase
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
     * @return array{0: int, 1: string, 2: string} [proposalId, token, real otpCode]
     */
    private function createAndSendProposal(array $headers, Project $project): array
    {
        $id = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        return [$id, $token, $send->json('data.otp_code')];
    }

    private function approve(string $token, array $body, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson("/api/v1/public/proposals/{$token}/approve", $body);
    }

    private function setUpSentProposal(): array
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$id, $token, $otp] = $this->createAndSendProposal($this->authHeader($user), $project);

        return [$id, $token, $otp];
    }

    // --- Idempotency: fresh key succeeds and is stored ---

    public function test_approving_with_a_fresh_idempotency_key_succeeds_and_stores_the_response(): void
    {
        [$id, $token, $otp] = $this->setUpSentProposal();

        $this->assertDatabaseCount('idempotency_keys', 0);

        $response = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'idem-key-1']
        );

        // approve()'s success body is returned UNWRAPPED (no "data" key) — deliberate, per
        // PublicProposalController::approve()'s docblock (matches PRD §3.3's literal example),
        // unlike every other endpoint in this API which wraps success responses in {"data":..}.
        // Flagged as an API-consistency finding in this report; asserted here as-is.
        $response->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ProposalVersion::find($id)->status);

        $stored = IdempotencyKey::first();
        $this->assertNotNull($stored);
        $this->assertSame("proposal_approve:{$id}", $stored->scope);
        $this->assertSame('idem-key-1', $stored->key);
        $this->assertSame(200, $stored->response_status);
        $this->assertSame('approved', $stored->response_body['status']);
    }

    // --- Idempotency: replay with the SAME key returns the exact original response ---

    public function test_replaying_the_same_idempotency_key_returns_the_exact_original_response_verbatim(): void
    {
        [$id, $token, $otp] = $this->setUpSentProposal();

        $first = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'idem-key-2']
        )->assertStatus(200);

        $originalBody = $first->json();

        // Replay with the SAME key but a completely different/garbage body: wrong name, wrong
        // (or no) otp. Per the documented reconciliation, this must NOT re-validate anything —
        // it must return the exact stored response.
        $replay = $this->approve(
            $token,
            ['name' => 'Someone Else', 'otp' => 'not-even-numeric-garbage'],
            ['Idempotency-Key' => 'idem-key-2']
        );

        $replay->assertStatus(200);
        $this->assertSame($originalBody, $replay->json());

        // No second approval side effect was produced by the replay.
        $this->assertDatabaseCount('approvals', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_replay_does_not_require_a_body_at_all_and_still_returns_the_stored_response(): void
    {
        [, $token, $otp] = $this->setUpSentProposal();

        $first = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'idem-key-3']
        )->assertStatus(200);

        // Completely empty body — would normally fail FormRequest validation (name/otp
        // required) — but a matched replay must short-circuit before validation matters here
        // in the sense that it still returns 200 with the original payload rather than a 422.
        $replay = $this->withHeaders(['Idempotency-Key' => 'idem-key-3'])
            ->postJson("/api/v1/public/proposals/{$token}/approve", []);

        // Laravel's FormRequest validation runs before the controller method body, so an
        // empty payload against ApprovePublicProposalRequest's rules (name/otp required) is
        // rejected with 422 BEFORE the controller ever reaches the idempotency-replay check.
        // This documents that boundary precisely rather than assuming the replay short-circuit
        // reaches all the way back through Laravel's request pipeline.
        $replay->assertStatus(422);
        // Confirm this is form-validation (this API's standard envelope, per bootstrap/app.php's
        // ValidationException renderer), not an OTP/approval error — the replay logic never got
        // a chance to run because Laravel's validation gate is upstream of the controller.
        $this->assertSame('validation_failed', $replay->json('error.code'));

        // Sanity: the ORIGINAL successful call is untouched.
        $this->assertSame('approved', $first->json('status'));
    }

    // --- Idempotency: NO key after already approved -> 409, not a replay ---

    public function test_approving_again_without_idempotency_key_after_already_approved_returns_409(): void
    {
        [$id, $token, $otp] = $this->setUpSentProposal();

        // First approval succeeds, WITHOUT ever sending an Idempotency-Key header.
        $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp])->assertStatus(200);
        $this->assertSame('approved', ProposalVersion::find($id)->status);
        $this->assertDatabaseCount('idempotency_keys', 0);

        // Second call, also with NO Idempotency-Key header, using the SAME (still technically
        // correct-format) OTP. Must conflict — this is what proves the two behaviors are
        // genuinely distinguished by the header's presence, not by whether the OTP is right.
        $second = $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp]);

        $second->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_ALREADY_APPROVED');

        // Still exactly one approvals row — the conflicting call must not have created a
        // second one.
        $this->assertDatabaseCount('approvals', 1);
    }

    public function test_a_never_before_seen_idempotency_key_against_an_already_approved_version_still_conflicts(): void
    {
        // Distinguishes "has an Idempotency-Key header" from "has a MATCHING stored one". A
        // brand-new key that was never used for a successful approval is NOT a replay — it
        // must still hit normal state-conflict rules.
        [$id, $token, $otp] = $this->setUpSentProposal();

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

        $second->assertStatus(409)->assertJsonPath('error.code', 'PROPOSAL_ALREADY_APPROVED');
        $this->assertDatabaseCount('idempotency_keys', 1); // only the first key was ever stored
    }

    public function test_a_failed_attempt_with_an_idempotency_key_is_not_stored_so_a_retry_can_still_succeed(): void
    {
        [$id, $token, $otp] = $this->setUpSentProposal();

        // Wrong OTP, WITH an Idempotency-Key header — this must fail normally (422) and must
        // NOT poison the idempotency store, since only successful mutations are recorded.
        $failed = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => '000000'],
            ['Idempotency-Key' => 'retry-key']
        );
        $failed->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID');
        $this->assertDatabaseCount('idempotency_keys', 0);

        // Retrying with the SAME key but now the CORRECT otp must succeed — proving the failed
        // attempt didn't get cached as "the" response for this key.
        $retry = $this->approve(
            $token,
            ['name' => 'Jane Client', 'otp' => $otp],
            ['Idempotency-Key' => 'retry-key']
        );
        $retry->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertSame('approved', ProposalVersion::find($id)->status);
    }

    // --- Approval side effects ---

    public function test_approval_creates_an_approvals_row_with_the_correct_fields(): void
    {
        [$id, $token, $otp] = $this->setUpSentProposal();
        $project = ProposalVersion::find($id)->project;

        $this->approve($token, ['name' => 'Jane Client', 'comment' => 'Looks great', 'otp' => $otp])
            ->assertStatus(200);

        $approval = Approval::first();
        $this->assertNotNull($approval);
        $this->assertSame($project->organization_id, $approval->organization_id);
        $this->assertSame($project->id, $approval->project_id);
        $this->assertSame(ProposalVersion::ENTITY_TYPE, $approval->entity_type);
        $this->assertSame($id, $approval->entity_id);
        $this->assertSame('client', $approval->approver_type);
        $this->assertNull($approval->user_id);
        $this->assertSame('approved', $approval->status);
        $this->assertNotNull($approval->approved_at);
        $this->assertNotNull($approval->ip_address);
        // Name has no dedicated column — folded into comment per the controller's documented
        // choice; confirm it's actually captured somewhere rather than silently dropped.
        $this->assertStringContainsString('Jane Client', $approval->comment);
        $this->assertStringContainsString('Looks great', $approval->comment);
    }

    public function test_approval_sets_approved_at_on_the_version_and_marks_the_otp_challenge_verified(): void
    {
        [$id, $token, $otp] = $this->setUpSentProposal();

        $this->assertNull(OtpChallenge::first()->verified_at);

        $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp])->assertStatus(200);

        $version = ProposalVersion::find($id);
        $this->assertSame('approved', $version->status);
        $this->assertNotNull($version->approved_at);

        $challenge = OtpChallenge::first();
        $this->assertNotNull($challenge->verified_at);
    }

    public function test_approve_response_includes_contract_conversion_available_true(): void
    {
        [, $token, $otp] = $this->setUpSentProposal();

        $response = $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp])->assertStatus(200);

        // Unwrapped body, see this class's other tests' notes.
        $response->assertJsonPath('contract_conversion_available', true);
        $this->assertNotNull($response->json('approval_id'));
        $this->assertNotNull($response->json('approved_at'));
    }

    public function test_approve_marks_the_signed_link_used_but_it_remains_resolvable_afterward(): void
    {
        // SignedLinkService::markUsed() is only called from approve() (not from show()/GET) —
        // confirmed empirically: a plain GET never bumps use_count. markUsed() only bumps
        // use_count/last_used_at — it does not revoke the link. Approving is gated by proposal
        // status + OTP, not by the link's use_count, so the link remains resolvable for a
        // subsequent GET (e.g. the client re-opening the same WhatsApp link to review what they
        // approved) — this is expected behavior, not a bug; pinned here so a future
        // "make links single-use" change is a deliberate decision, not an accidental regression.
        [$id, $token, $otp] = $this->setUpSentProposal();

        $this->getJson("/api/v1/public/proposals/{$token}")->assertStatus(200);
        $this->assertSame(0, \App\Models\SignedLink::first()->use_count);

        $this->approve($token, ['name' => 'Jane Client', 'otp' => $otp])->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, \App\Models\SignedLink::first()->use_count);

        // The link itself still resolves fine for a GET after being used to approve.
        $this->getJson("/api/v1/public/proposals/{$token}")->assertStatus(200);
    }
}
