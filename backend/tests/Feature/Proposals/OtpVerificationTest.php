<?php

namespace Tests\Feature\Proposals;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\Role;
use App\Models\SignedLink;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 4 -> POST /public/proposals/{token}/approve OTP validation
 * (App\Services\Proposals\OtpChallengeService::verify()): correct code approves; wrong code
 * returns 422 OTP_INVALID with attempts_remaining and increments attempts; hitting the 5-attempt
 * cap (OtpChallengeService::MAX_ATTEMPTS) returns 422 OTP_LOCKED; an expired-but-otherwise-valid
 * code is rejected distinctly as OTP_EXPIRED.
 *
 * IMPORTANT test-fixture note: OtpChallengeFactory's default `code_hash` is a plain
 * `hash('sha256', '123456')`, not a real `Hash::make()` bcrypt hash. `OtpChallengeService::verify()`
 * catches the `RuntimeException` that `Hash::check()` throws against a non-bcrypt string and
 * fails closed (treats it as a wrong code) rather than letting it bubble up — see that method's
 * inline comment. So a challenge built with the factory default DOES verify() safely, but always
 * as "invalid", never as a match — it can never be used to test the success path. Every test
 * below that needs a genuinely matchable OTP either goes through the real POST .../send flow, or
 * explicitly overrides `code_hash` with `Hash::make('123456')` when hand-building a challenge.
 */
class OtpVerificationTest extends TestCase
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

    private function approve(string $token, string $otp, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers)
            ->postJson("/api/v1/public/proposals/{$token}/approve", [
                'name' => 'Jane Client',
                'otp' => $otp,
            ]);
    }

    public function test_correct_otp_approves_the_proposal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$id, $token, $realOtp] = $this->createAndSendProposal($this->authHeader($user), $project);

        $response = $this->approve($token, $realOtp);

        // approve()'s success body is returned UNWRAPPED (no "data" key) — deliberate, per
        // PublicProposalController::approve()'s docblock (matches PRD §3.3's literal example),
        // unlike every other endpoint in this API which wraps success responses in {"data":..}.
        $response->assertStatus(200)->assertJsonPath('status', 'approved');
        $this->assertSame('approved', \App\Models\ProposalVersion::find($id)->status);
    }

    public function test_wrong_otp_returns_422_otp_invalid_with_attempts_remaining_and_increments_attempts(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $response = $this->approve($token, '000000');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'OTP_INVALID')
            ->assertJsonPath('error.details.attempts_remaining', 4);

        $challenge = OtpChallenge::first();
        $this->assertSame(1, $challenge->attempts);
        $this->assertNull($challenge->verified_at);
        // The proposal itself must remain unapproved after a failed attempt.
        $this->assertSame('sent', \App\Models\ProposalVersion::first()->status);
    }

    public function test_attempts_remaining_counts_down_correctly_across_repeated_wrong_attempts(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->approve($token, '000000')->assertJsonPath('error.details.attempts_remaining', 4);
        $this->approve($token, '000000')->assertJsonPath('error.details.attempts_remaining', 3);
        $this->approve($token, '000000')->assertJsonPath('error.details.attempts_remaining', 2);

        $this->assertSame(3, OtpChallenge::first()->attempts);
    }

    public function test_five_failed_attempts_locks_out_and_returns_422_otp_locked(): void
    {
        // OtpChallengeService::MAX_ATTEMPTS = 5, confirmed directly in ProposalSendTest too.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token, $realOtp] = $this->createAndSendProposal($this->authHeader($user), $project);

        // Burn all 5 attempts with the wrong code.
        for ($i = 0; $i < 5; $i++) {
            $this->approve($token, '000000')->assertStatus(422);
        }

        $this->assertSame(5, OtpChallenge::first()->attempts);

        // The 6th call — even with the WRONG code — reports LOCKED, not INVALID.
        $lockedWithWrongCode = $this->approve($token, '111111');
        $lockedWithWrongCode->assertStatus(422)->assertJsonPath('error.code', 'OTP_LOCKED');

        // And even the genuinely correct code is now rejected too — lockout is absolute, not
        // "still allow the right answer."
        $lockedWithRightCode = $this->approve($token, $realOtp);
        $lockedWithRightCode->assertStatus(422)->assertJsonPath('error.code', 'OTP_LOCKED');
        $this->assertSame('sent', \App\Models\ProposalVersion::first()->status);
    }

    public function test_expired_otp_is_rejected_distinctly_as_otp_expired(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token, $realOtp] = $this->createAndSendProposal($this->authHeader($user), $project);

        OtpChallenge::query()->update(['expires_at' => now()->subMinute()]);

        // Even the CORRECT code is rejected once expired — expiry is checked before the hash
        // comparison (OtpChallengeService::verify()'s documented check order).
        $response = $this->approve($token, $realOtp);

        $response->assertStatus(422)->assertJsonPath('error.code', 'OTP_EXPIRED');
        $this->assertSame('sent', \App\Models\ProposalVersion::first()->status);
        // Expiry is checked before hash comparison, so attempts should NOT increment either.
        $this->assertSame(0, OtpChallenge::first()->attempts);
    }

    public function test_expired_check_takes_priority_over_lock_check_ordering_is_lock_then_expiry(): void
    {
        // OtpChallengeService::verify() checks lockout first, then expiry, then the hash. Once
        // BOTH conditions are true (locked AND expired), the reported reason must be "locked"
        // per that documented order — pins the exact precedence so a future refactor that
        // swaps the check order is caught here.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        OtpChallenge::query()->update(['attempts' => 5, 'expires_at' => now()->subMinute()]);

        $this->approve($token, '000000')->assertStatus(422)->assertJsonPath('error.code', 'OTP_LOCKED');
    }

    public function test_otp_challenge_factory_default_hash_fails_closed_as_invalid_not_a_crash(): void
    {
        // Documents (rather than silently relying on) the factory/service hashing-scheme
        // mismatch described in this class's docblock: constructing a challenge via the
        // factory's default (non-bcrypt) hash and feeding it through the REAL
        // OtpChallengeService must fail closed as a wrong code — never throw, never match.
        $link = SignedLink::factory()->create();
        $challenge = OtpChallenge::factory()->create(['signed_link_id' => $link->id]);

        $this->expectException(\App\Services\Proposals\OtpVerificationException::class);

        try {
            app(\App\Services\Proposals\OtpChallengeService::class)->verify($challenge, '123456');
        } finally {
            $challenge->refresh();
            $this->assertSame(1, $challenge->attempts, 'a malformed hash must still count as a failed attempt');
        }
    }
}
