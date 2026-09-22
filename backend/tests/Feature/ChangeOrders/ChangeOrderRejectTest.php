<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\Approval;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 7 "Reject" step -> POST /public/change-orders/{token}/reject:
 * mirrors proposals' request-changes (no OTP, lower stakes than approval). Sets status =
 * 'rejected', creates an approvals row (entity_type = 'change_order', status = 'rejected'), and
 * is terminal — a rejected change order can never later be applied.
 */
class ChangeOrderRejectTest extends TestCase
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

    /**
     * @return array{0: int, 1: string} [changeOrderId, token]
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

        return [$id, $token];
    }

    public function test_reject_requires_no_otp(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        [, $token] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        // No 'otp' field in the body at all — RejectPublicChangeOrderRequest doesn't require one.
        $this->postJson("/api/v1/public/change-orders/{$token}/reject", [
            'name' => 'Jane Client',
            'comment' => 'We changed our mind, please skip this.',
        ])->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    public function test_reject_sets_status_rejected_and_creates_an_approvals_row(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        [$id, $token] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/change-orders/{$token}/reject", [
            'name' => 'Jane Client',
            'comment' => 'Not needed anymore.',
        ])->assertStatus(200);

        $changeOrder = ChangeOrder::find($id);
        $this->assertSame('rejected', $changeOrder->status);

        $approval = Approval::first();
        $this->assertNotNull($approval);
        $this->assertSame('change_order', $approval->entity_type);
        $this->assertSame($id, $approval->entity_id);
        $this->assertSame('rejected', $approval->status);
        $this->assertSame('client', $approval->approver_type);
        $this->assertNull($approval->user_id);
        $this->assertStringContainsString('Jane Client', $approval->comment);
    }

    public function test_a_rejected_change_order_cannot_later_be_applied(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        \App\Models\Contract::factory()->create(['project_id' => $project->id]);
        [$id, $token] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        $this->postJson("/api/v1/public/change-orders/{$token}/reject", [
            'name' => 'Jane Client',
            'comment' => 'No thanks.',
        ])->assertStatus(200);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$id}/apply")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_APPROVED');

        $this->assertSame('rejected', ChangeOrder::find($id)->status);
        $this->assertNull(ChangeOrder::find($id)->applied_at);
    }

    public function test_reject_fails_409_when_already_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        [$id, $token] = $this->createAndSendChangeOrder($this->authHeader($user), $project);

        // Approve it directly at the model level (bypassing OTP) to set up the "already
        // approved" precondition without depending on the approval flow being correct too.
        ChangeOrder::find($id)->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

        $this->postJson("/api/v1/public/change-orders/{$token}/reject", [
            'name' => 'Jane Client',
            'comment' => 'Too late.',
        ])->assertStatus(409)->assertJsonPath('error.code', 'CHANGE_ORDER_ALREADY_APPROVED');

        $this->assertSame('approved', ChangeOrder::find($id)->status);
    }
}
