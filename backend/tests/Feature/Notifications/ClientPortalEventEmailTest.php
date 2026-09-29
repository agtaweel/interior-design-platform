<?php

namespace Tests\Feature\Notifications;

use App\Mail\ClientPortalEventMail;
use App\Models\BoqCategory;
use App\Models\BoqItem;
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
 * Platform Readiness Review finding #08: approving/rejecting/requesting changes on a proposal
 * or change order via the public portal now emails the client a confirmation, closing the loop
 * that previously only pinged staff (NotificationService) — the client got no acknowledgment
 * that their own decision was received.
 */
class ClientPortalEventEmailTest extends TestCase
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
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    private function seedBoqItem(Project $project): void
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
    }

    /** @return array{0: int, 1: string, 2: string} [proposalId, token, otpCode] */
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

    /** @return array{0: int, 1: string, 2: string} [changeOrderId, token, otpCode] */
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

    public function test_approving_a_proposal_emails_the_client_a_confirmation(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => 'client@example.com']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);

        [, $token, $otp] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/approve", ['name' => 'Jane Client', 'otp' => $otp])
            ->assertStatus(200);

        Mail::assertSent(ClientPortalEventMail::class, function (ClientPortalEventMail $mail) {
            return $mail->hasTo('client@example.com')
                && $mail->outcome === 'approved'
                && $mail->documentLabel === 'proposal';
        });
    }

    public function test_requesting_changes_on_a_proposal_emails_the_client_a_confirmation(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => 'client@example.com']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);

        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/request-changes", [
            'name' => 'Jane Client',
            'comment' => 'Please swap the flooring material.',
        ])->assertStatus(200);

        Mail::assertSent(ClientPortalEventMail::class, function (ClientPortalEventMail $mail) {
            return $mail->hasTo('client@example.com') && $mail->outcome === 'changes_requested';
        });
    }

    public function test_approving_a_change_order_emails_the_client_a_confirmation(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => 'client@example.com']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->fullAccessUser($organization);

        [, $token, $otp] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/change-orders/{$token}/approve", ['name' => 'Jane Client', 'otp' => $otp])
            ->assertStatus(200);

        Mail::assertSent(ClientPortalEventMail::class, function (ClientPortalEventMail $mail) {
            return $mail->hasTo('client@example.com')
                && $mail->outcome === 'approved'
                && $mail->documentLabel === 'change order';
        });
    }

    public function test_rejecting_a_change_order_emails_the_client_a_confirmation(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => 'client@example.com']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->fullAccessUser($organization);

        [, $token] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/change-orders/{$token}/reject", [
            'name' => 'Jane Client',
            'comment' => 'We no longer need this change.',
        ])->assertStatus(200);

        Mail::assertSent(ClientPortalEventMail::class, function (ClientPortalEventMail $mail) {
            return $mail->hasTo('client@example.com') && $mail->outcome === 'rejected';
        });
    }

    public function test_approving_never_fails_when_the_client_has_no_email_on_file(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id, 'email' => null]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);

        [, $token, $otp] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/approve", ['name' => 'Jane Client', 'otp' => $otp])
            ->assertStatus(200);

        Mail::assertNotSent(ClientPortalEventMail::class);
    }
}
