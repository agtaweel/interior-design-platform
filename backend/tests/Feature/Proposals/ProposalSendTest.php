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
use App\Services\Proposals\OtpChallengeService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 4 -> POST /proposals/{id}/send: transitions draft -> sent, creates
 * exactly one signed_links row + one otp_challenges row, finalizes snapshot_json, sets sent_at,
 * and returns the plaintext OTP code in the response ONLY there — never persisted in plaintext,
 * never retrievable again via any GET.
 */
class ProposalSendTest extends TestCase
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

    private function createDraft(array $headers, Project $project): int
    {
        return $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');
    }

    public function test_send_transitions_draft_to_sent_and_sets_sent_at(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);
        $id = $this->createDraft($headers, $project);

        $this->assertNull(\App\Models\ProposalVersion::find($id)->sent_at);

        $response = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $response->assertJsonPath('data.status', 'sent');
        $fresh = \App\Models\ProposalVersion::find($id);
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNotNull($fresh->snapshot_json);
    }

    public function test_send_creates_exactly_one_signed_link_and_one_otp_challenge_row(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);
        $id = $this->createDraft($headers, $project);

        $this->assertDatabaseCount('signed_links', 0);
        $this->assertDatabaseCount('otp_challenges', 0);

        $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $this->assertDatabaseCount('signed_links', 1);
        $this->assertDatabaseCount('otp_challenges', 1);

        $link = SignedLink::first();
        $this->assertSame('proposal_approval', $link->purpose);
        $this->assertSame($id, $link->payload_json['proposal_version_id']);

        $challenge = OtpChallenge::first();
        $this->assertSame($link->id, $challenge->signed_link_id);
        $this->assertSame(0, $challenge->attempts);
        $this->assertNull($challenge->verified_at);
    }

    public function test_response_includes_public_url_and_plaintext_otp_and_only_the_hash_is_stored(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);
        $id = $this->createDraft($headers, $project);

        $response = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);

        $otpCode = $response->json('data.otp_code');
        $publicUrl = $response->json('data.public_url');

        $this->assertNotEmpty($otpCode);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otpCode);
        $this->assertStringContainsString('/p/proposals/', $publicUrl);

        // The stored hash must actually correspond to the plaintext code returned (bcrypt via
        // Hash::make, per OtpChallengeService) — not merely "some hash exists".
        $challenge = OtpChallenge::first();
        $this->assertNotSame($otpCode, $challenge->code_hash);
        $this->assertTrue(Hash::check($otpCode, $challenge->code_hash));

        // Never persisted anywhere in plaintext: neither the proposal_versions row (content or
        // snapshot) nor any other column contains the raw code.
        $fresh = \App\Models\ProposalVersion::find($id);
        $this->assertStringNotContainsString($otpCode, json_encode($fresh->snapshot_json));
        $this->assertStringNotContainsString($otpCode, json_encode($fresh->content_json));
    }

    public function test_otp_code_is_not_retrievable_again_via_any_get_endpoint(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        $headers = $this->authHeader($user);
        $id = $this->createDraft($headers, $project);

        $sendResponse = $this->withHeaders($headers)->postJson("/api/v1/proposals/{$id}/send")->assertStatus(200);
        $otpCode = $sendResponse->json('data.otp_code');

        // GET /proposals/{id} (internal detail) must not leak it anywhere in the payload.
        $show = $this->withHeaders($headers)->getJson("/api/v1/proposals/{$id}")->assertStatus(200);
        $this->assertStringNotContainsString($otpCode, $show->getContent());

        // GET /projects/{id}/proposals (list) likewise.
        $index = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/proposals")->assertStatus(200);
        $this->assertStringNotContainsString($otpCode, $index->getContent());
    }

    public function test_otp_expiry_is_seventy_two_hours_and_max_attempts_is_five_per_implementation(): void
    {
        // Pins the documented constants directly (OtpChallengeService's docblock/const) so a
        // silent change to these security-relevant values is caught by this suite rather than
        // only being visible in a code comment.
        $this->assertSame(72, OtpChallengeService::EXPIRY_HOURS);
        $this->assertSame(5, OtpChallengeService::MAX_ATTEMPTS);
    }
}
