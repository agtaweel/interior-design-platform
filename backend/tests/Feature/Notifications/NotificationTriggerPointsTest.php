<?php

namespace Tests\Feature\Notifications;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Notification;
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
 * PROJECT_CONTEXT.md Sprint 8 "Notifications" -> end-to-end coverage of the six wiring points,
 * hitting the REAL endpoints (not unit-testing NotificationService in isolation — that's
 * NotificationRecipientResolutionTest) and asserting a `notifications` row actually lands with
 * the right type/payload. Every project here has NO responsible_user_id set, so every
 * notification below is exercising the "fallback to MANAGE_BOQ holders" path with the acting
 * staff user itself as the sole holder — the recipient-resolution logic itself (responsible user
 * vs multi-holder fallback) is covered exhaustively in NotificationRecipientResolutionTest;
 * this class's job is purely "does hitting the real controller action actually produce a
 * notification row with the right type/payload for each of the six trigger points."
 */
class NotificationTriggerPointsTest extends TestCase
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

        return Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'responsible_user_id' => null,
        ]);
    }

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);
    }

    private function seedBoqItem(Project $project): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id]);
    }

    /** @return array{0: int, 1: string, 2: string} [proposalId, token, otp] */
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

    // --- 1. Proposal approved ---

    public function test_approving_a_proposal_end_to_end_creates_a_notification_for_the_manage_boq_holder(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$proposalId, $token, $otp] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->assertDatabaseCount('notifications', 0);

        $this->postJson("/api/v1/public/proposals/{$token}/approve", ['name' => 'Jane Client', 'otp' => $otp])
            ->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame($organization->id, $notification->organization_id);
        $this->assertSame('proposal_approved', $notification->type);
        $this->assertSame($project->id, $notification->payload_json['project_id']);
        $this->assertSame($proposalId, $notification->payload_json['proposal_version_id']);
    }

    // --- 2. Proposal changes requested ---

    public function test_requesting_changes_on_a_proposal_end_to_end_creates_a_notification(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [, $token] = $this->createAndSendProposal($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/proposals/{$token}/request-changes", [
            'name' => 'Jane Client', 'comment' => 'Please change the tile color',
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('proposal_changes_requested', $notification->type);
        $this->assertSame($project->id, $notification->payload_json['project_id']);
    }

    // --- 3. Contract created ---

    public function test_converting_an_approved_proposal_to_a_contract_end_to_end_creates_a_notification(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);

        $this->assertDatabaseCount('notifications', 0);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        $contractId = $response->json('data.id');

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('contract_created', $notification->type);
        $this->assertSame($project->id, $notification->payload_json['project_id']);
        $this->assertSame($contractId, $notification->payload_json['contract_id']);
    }

    // --- 4. Payment received ---

    public function test_recording_a_payment_end_to_end_creates_a_notification(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => '100000.00',
        ]);
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'percentage' => null,
            'amount' => '1000.00',
            'status' => 'pending',
        ]);

        $this->assertDatabaseCount('notifications', 0);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '400.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('payment_received', $notification->type);
        $this->assertSame($project->id, $notification->payload_json['project_id']);
        $this->assertSame('400.00', (string) $notification->payload_json['amount']);
    }

    // --- 5 & 6. Change order approved / rejected ---

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

    public function test_approving_a_change_order_end_to_end_creates_a_notification(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$changeOrderId, $token, $otp] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/change-orders/{$token}/approve", ['name' => 'Jane Client', 'otp' => $otp])
            ->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('change_order_approved', $notification->type);
        $this->assertSame($changeOrderId, $notification->payload_json['change_order_id']);
    }

    public function test_rejecting_a_change_order_end_to_end_creates_a_notification(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedBoqItem($project);
        [$changeOrderId, $token] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/change-orders/{$token}/reject", [
            'name' => 'Jane Client', 'comment' => 'Too expensive',
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('change_order_rejected', $notification->type);
        $this->assertSame($changeOrderId, $notification->payload_json['change_order_id']);
    }
}
