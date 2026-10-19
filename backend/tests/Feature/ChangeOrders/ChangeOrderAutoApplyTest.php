<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FinancialTransaction;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/SIMPLIFICATION_REVIEW.md P2.4 — organizations.auto_apply_change_orders: when on, the
 * client's approval through the public link also applies the change order to the BOQ and the
 * contract, in the same step. Off by default; never makes the approval itself fail.
 */
class ChangeOrderAutoApplyTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $organization, array $permissions): User
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

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function projectWithContract(Organization $organization, bool $withContract = true): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        if ($withContract) {
            $proposal = ProposalVersion::factory()->create(['project_id' => $project->id, 'status' => 'approved']);
            Contract::factory()->create([
                'project_id' => $project->id,
                'proposal_version_id' => $proposal->id,
                'contract_value' => '10000.00',
            ]);
        }

        return $project;
    }

    /** Staff create + send a +1000.00 change order; returns [changeOrderId, token, otp]. */
    private function sendChangeOrder(User $staff, Project $project): array
    {
        $id = $this->withHeaders($this->headers($staff))
            ->postJson("/api/v1/projects/{$project->id}/change-orders", [
                'reason' => 'Client asked for an upgraded tile.',
                'items' => [
                    ['action' => 'add', 'description' => 'Upgraded Tile', 'quantity' => 2, 'unit' => 'm2', 'new_unit_price' => 500],
                ],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $send = $this->withHeaders($this->headers($staff))->postJson("/api/v1/change-orders/{$id}/send")->assertStatus(200);
        $token = basename((string) parse_url($send->json('data.public_url'), PHP_URL_PATH));

        return [$id, $token, $send->json('data.otp_code')];
    }

    /** The client is anonymous: drop any staff auth left over from earlier requests in this test. */
    private function clientApproves(string $token, string $otp)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->postJson("/api/v1/public/change-orders/{$token}/approve", ['name' => 'Ahmed Client', 'otp' => $otp]);
    }

    public function test_off_by_default_approval_leaves_the_change_order_for_manual_apply(): void
    {
        $organization = Organization::factory()->create();
        $this->assertFalse((bool) $organization->fresh()->auto_apply_change_orders);
        $project = $this->projectWithContract($organization);
        [$id, $token, $otp] = $this->sendChangeOrder($this->member($organization, [Permissions::MANAGE_BOQ => true]), $project);

        $this->clientApproves($token, $otp)->assertStatus(200)->assertJsonPath('applied', false);

        $this->assertSame('approved', ChangeOrder::find($id)->status);
        $this->assertSame('10000.00', Contract::where('project_id', $project->id)->value('contract_value'));
        $this->assertFalse(BoqItem::where('project_id', $project->id)->where('name', 'Upgraded Tile')->exists());
    }

    public function test_when_on_the_approval_applies_it_to_boq_contract_and_ledger_attributed_to_its_creator(): void
    {
        $organization = Organization::factory()->create(['auto_apply_change_orders' => true]);
        $project = $this->projectWithContract($organization);
        $staff = $this->member($organization, [Permissions::MANAGE_BOQ => true]);
        [$id, $token, $otp] = $this->sendChangeOrder($staff, $project);

        $this->clientApproves($token, $otp)
            ->assertStatus(200)
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('applied', true);

        $changeOrder = ChangeOrder::find($id);
        $this->assertSame('applied', $changeOrder->status);
        $this->assertNotNull($changeOrder->approved_at);
        $this->assertNotNull($changeOrder->applied_at);
        $this->assertSame('11000.00', Contract::where('project_id', $project->id)->value('contract_value'));
        $this->assertTrue(BoqItem::where('project_id', $project->id)->where('name', 'Upgraded Tile')->exists());

        $ledger = FinancialTransaction::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('type', FinancialTransaction::TYPE_CHANGE_ORDER_CHARGE)
            ->first();
        $this->assertNotNull($ledger);
        $this->assertSame((int) $staff->id, (int) $ledger->created_by);

        // The approval is still recorded as the client's.
        $this->assertDatabaseHas('approvals', ['entity_id' => $id, 'approver_type' => 'client', 'status' => 'approved']);
    }

    public function test_when_on_but_no_contract_exists_it_is_only_approved(): void
    {
        $organization = Organization::factory()->create(['auto_apply_change_orders' => true]);
        $project = $this->projectWithContract($organization, withContract: false);
        [$id, $token, $otp] = $this->sendChangeOrder($this->member($organization, [Permissions::MANAGE_BOQ => true]), $project);

        $this->clientApproves($token, $otp)->assertStatus(200)->assertJsonPath('applied', false);

        $this->assertSame('approved', ChangeOrder::find($id)->status);
    }

    public function test_when_its_creator_no_longer_exists_it_is_only_approved(): void
    {
        $organization = Organization::factory()->create(['auto_apply_change_orders' => true]);
        $project = $this->projectWithContract($organization);
        [$id, $token, $otp] = $this->sendChangeOrder($this->member($organization, [Permissions::MANAGE_BOQ => true]), $project);
        ChangeOrder::whereKey($id)->update(['requested_by' => null]); // e.g. the staff account was deleted

        $this->clientApproves($token, $otp)->assertStatus(200)->assertJsonPath('applied', false);

        $this->assertSame('approved', ChangeOrder::find($id)->status);
        $this->assertSame('10000.00', Contract::where('project_id', $project->id)->value('contract_value'));
    }

    public function test_only_members_who_manage_the_organization_can_turn_it_on(): void
    {
        $organization = Organization::factory()->create();
        $designer = $this->member($organization, [Permissions::MANAGE_BOQ => true]);
        $owner = $this->member($organization, [Permissions::MANAGE_ORGANIZATION => true]);
        $tenant = ['X-Organization-Id' => (string) $organization->id];

        $this->withHeaders($this->headers($designer) + $tenant)
            ->patchJson("/api/v1/organizations/{$organization->id}", ['auto_apply_change_orders' => true])
            ->assertStatus(403);
        $this->assertFalse((bool) $organization->fresh()->auto_apply_change_orders);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->headers($owner) + $tenant)
            ->patchJson("/api/v1/organizations/{$organization->id}", ['auto_apply_change_orders' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.auto_apply_change_orders', true);
        $this->assertTrue((bool) $organization->fresh()->auto_apply_change_orders);
    }
}
