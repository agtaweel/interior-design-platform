<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 8 "Notifications" -> NotificationService::notify()'s recipient
 * resolution: the project's responsible_user_id if set, otherwise EVERY organization member
 * holding Permissions::MANAGE_BOQ (a "never silently drop a notification" fallback). Exercises
 * NotificationService directly (not through an HTTP trigger point — that's
 * NotificationTriggerPointsTest) so the recipient-resolution logic itself is pinned precisely,
 * independent of which of the six controllers happens to call it.
 */
class NotificationRecipientResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPermissions(Organization $organization, array $permissions, string $status = 'active'): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => $status,
        ]);

        return $user;
    }

    private function projectIn(Organization $organization, ?int $responsibleUserId = null): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'responsible_user_id' => $responsibleUserId,
        ]);
    }

    public function test_notifies_only_the_responsible_user_when_one_is_set_even_though_other_manage_boq_holders_exist(): void
    {
        $organization = Organization::factory()->create();
        $responsible = $this->memberWithPermissions($organization, []); // no manage_boq at all
        // Other members who DO hold manage_boq must NOT be notified once a responsible user exists.
        $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $project = $this->projectIn($organization, $responsible->id);

        app(NotificationService::class)->notify($project, 'proposal_approved', [
            'project_id' => $project->id,
            'summary' => 'test',
        ]);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($responsible->id, $notification->user_id);
        $this->assertSame($organization->id, $notification->organization_id);
        $this->assertSame('in_app', $notification->channel);
        $this->assertSame('proposal_approved', $notification->type);
        $this->assertSame($project->id, $notification->payload_json['project_id']);
    }

    public function test_falls_back_to_every_manage_boq_holder_when_no_responsible_user_is_set_and_each_gets_exactly_one(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization, null);

        $holderA = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $holderB = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        // A member without manage_boq must NOT be notified via the fallback.
        $this->memberWithPermissions($organization, []);
        // An inactive manage_boq holder must NOT be notified either — the fallback only
        // considers active memberships (matches NotificationService::resolveRecipientUserIds()'s
        // ->where('status', 'active') filter).
        $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true], status: 'invited');

        app(NotificationService::class)->notify($project, 'contract_created', [
            'project_id' => $project->id,
            'summary' => 'test',
        ]);

        $this->assertDatabaseCount('notifications', 2);
        $recipientIds = Notification::query()->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$holderA->id, $holderB->id], $recipientIds);

        foreach (Notification::all() as $notification) {
            $this->assertSame('contract_created', $notification->type);
            $this->assertSame($organization->id, $notification->organization_id);
        }
    }

    public function test_a_member_holding_manage_boq_in_a_different_organization_is_never_notified(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $project = $this->projectIn($orgA, null);

        $holderInA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_BOQ => true]);
        // Same permission, but a member of a completely different organization.
        $this->memberWithPermissions($orgB, [Permissions::MANAGE_BOQ => true]);

        app(NotificationService::class)->notify($project, 'payment_received', ['project_id' => $project->id]);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($holderInA->id, Notification::first()->user_id);
    }

    public function test_no_notification_is_silently_created_when_there_is_no_responsible_user_and_no_manage_boq_holder(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization, null);
        // Only a non-manage_boq member exists.
        $this->memberWithPermissions($organization, []);

        app(NotificationService::class)->notify($project, 'change_order_rejected', ['project_id' => $project->id]);

        $this->assertDatabaseCount('notifications', 0);
    }
}
