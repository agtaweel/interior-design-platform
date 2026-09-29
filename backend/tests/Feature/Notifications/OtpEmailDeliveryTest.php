<?php

namespace Tests\Feature\Notifications;

use App\Mail\OtpCodeMail;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Platform Readiness Review finding #03: sending a proposal or change order for approval now
 * ALSO emails the client their OTP + review link automatically (ProposalSendService/
 * ChangeOrderSendService), rather than relying solely on staff manually relaying the code and
 * link returned in the send() response (that manual-relay path is unchanged and still works —
 * this is additive).
 */
class OtpEmailDeliveryTest extends TestCase
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

    public function test_sending_a_proposal_emails_the_client_their_otp_and_link(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => 'client@example.com']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        $proposalId = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/proposals/{$proposalId}/send")
            ->assertStatus(200);

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use ($send) {
            return $mail->hasTo('client@example.com')
                && $mail->code === $send->json('data.otp_code')
                && $mail->publicUrl === $send->json('data.public_url')
                && $mail->documentLabel === 'proposal';
        });

        // The manual-relay fields staff have always used are still returned unchanged.
        $this->assertNotNull($send->json('data.otp_code'));
        $this->assertNotNull($send->json('data.public_url'));
    }

    public function test_sending_a_change_order_emails_the_client_their_otp_and_link(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => 'client@example.com']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $changeOrder = ChangeOrder::factory()->create(['project_id' => $project->id, 'status' => 'draft']);

        $send = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/send")
            ->assertStatus(200);

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use ($send) {
            return $mail->hasTo('client@example.com')
                && $mail->code === $send->json('data.otp_code')
                && $mail->documentLabel === 'change order';
        });
    }

    public function test_sending_never_fails_when_the_client_has_no_email_on_file(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => null]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        $proposalId = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/proposals")
            ->assertStatus(201)
            ->json('data.id');

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/proposals/{$proposalId}/send")
            ->assertStatus(200);

        Mail::assertNothingSent();
    }
}
