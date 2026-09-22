<?php

namespace Tests\Feature\ChangeOrders;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 7 lifecycle: draft -> sent -> approved|rejected -> applied (only
 * reachable from approved). Pins each state-transition guard: PATCH only while draft (409
 * CHANGE_ORDER_NOT_EDITABLE otherwise), send only from draft (409 CHANGE_ORDER_NOT_DRAFT
 * otherwise), apply only from approved (409 CHANGE_ORDER_NOT_APPROVED otherwise) — covering
 * every other reachable status (draft, sent, rejected) as an apply precondition failure.
 */
class ChangeOrderLifecycleTest extends TestCase
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

    private function draftChangeOrder(Project $project): ChangeOrder
    {
        $changeOrder = ChangeOrder::factory()->create(['project_id' => $project->id]);
        \App\Models\ChangeOrderItem::factory()->create([
            'change_order_id' => $changeOrder->id,
            'line_delta' => '500.00',
        ]);
        $changeOrder->forceFill(['price_delta' => '500.00'])->save();

        return $changeOrder;
    }

    // --- PATCH: only while draft ---

    public function test_patch_succeeds_while_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/change-orders/{$changeOrder->id}", ['reason' => 'Updated reason'])
            ->assertStatus(200)
            ->assertJsonPath('data.reason', 'Updated reason');
    }

    public function test_patch_fails_409_when_sent(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'sent', 'sent_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/change-orders/{$changeOrder->id}", ['reason' => 'Nope'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_EDITABLE');

        $this->assertNotSame('Nope', $changeOrder->fresh()->reason);
    }

    public function test_patch_fails_409_when_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'approved', 'sent_at' => now(), 'approved_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/change-orders/{$changeOrder->id}", ['reason' => 'Nope'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_EDITABLE');
    }

    public function test_patch_fails_409_when_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'rejected', 'sent_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/change-orders/{$changeOrder->id}", ['reason' => 'Nope'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_EDITABLE');
    }

    public function test_patch_fails_409_when_applied(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'applied', 'sent_at' => now(), 'approved_at' => now(), 'applied_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/change-orders/{$changeOrder->id}", ['reason' => 'Nope'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_EDITABLE');
    }

    // --- send: only from draft ---

    public function test_send_succeeds_from_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/send");

        $response->assertStatus(200)->assertJsonPath('data.status', 'sent');
        $this->assertNotNull($response->json('data.otp_code'));
        $this->assertNotNull($response->json('data.public_url'));
        $this->assertNotNull($changeOrder->fresh()->sent_at);
    }

    public function test_send_fails_409_when_already_sent(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'sent', 'sent_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/send")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_DRAFT');
    }

    public function test_send_fails_409_when_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'approved', 'sent_at' => now(), 'approved_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/send")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_DRAFT');
    }

    // --- apply: only from approved ---

    private function contractFor(Project $project): Contract
    {
        return Contract::factory()->create(['project_id' => $project->id]);
    }

    public function test_apply_fails_409_when_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->contractFor($project);
        $changeOrder = $this->draftChangeOrder($project);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/apply")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_APPROVED');
    }

    public function test_apply_fails_409_when_sent_but_not_yet_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->contractFor($project);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'sent', 'sent_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/apply")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_APPROVED');
    }

    public function test_apply_fails_409_when_rejected(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->contractFor($project);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'rejected', 'sent_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/apply")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CHANGE_ORDER_NOT_APPROVED');
    }

    public function test_apply_succeeds_from_approved(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->contractFor($project);
        $changeOrder = $this->draftChangeOrder($project);
        $changeOrder->update(['status' => 'approved', 'sent_at' => now(), 'approved_at' => now()]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/apply")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'applied');

        $this->assertNotNull($changeOrder->fresh()->applied_at);
    }
}
