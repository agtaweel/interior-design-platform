<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 8 "Notifications" API: GET /notifications, POST
 * /notifications/{id}/read, POST /notifications/read-all (NotificationController). Per that
 * controller's docblock, there's no Permissions::* gate at all — the only authorization that
 * matters is "is this notification mine", so this class focuses entirely on user-scoping
 * (including WITHIN the same organization, not just cross-tenant), pagination/ordering, and the
 * idempotent/404-not-leak semantics of the mark-read endpoints.
 */
class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function memberIn(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => []]);
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

    // --- GET /notifications ---

    public function test_index_returns_only_the_current_users_own_notifications_even_within_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $userA = $this->memberIn($organization);
        $userB = $this->memberIn($organization);

        Notification::factory()->for($organization)->create(['user_id' => $userA->id, 'type' => 'proposal_approved']);
        Notification::factory()->for($organization)->create(['user_id' => $userA->id, 'type' => 'contract_created']);
        // Same organization, different user — must never appear in userA's list.
        Notification::factory()->for($organization)->create(['user_id' => $userB->id, 'type' => 'payment_received']);

        $response = $this->withHeaders($this->authHeader($userA))->getJson('/api/v1/notifications');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $types = collect($response->json('data'))->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['proposal_approved', 'contract_created'], $types);
    }

    public function test_index_is_ordered_newest_first(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberIn($organization);

        $oldest = Notification::factory()->for($organization)->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
        ]);
        $newest = Notification::factory()->for($organization)->create([
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
        $middle = Notification::factory()->for($organization)->create([
            'user_id' => $user->id,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/notifications');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids);
    }

    public function test_index_is_paginated(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberIn($organization);
        Notification::factory()->for($organization)->count(25)->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/notifications');

        $response->assertStatus(200)->assertJsonCount(20, 'data'); // default per_page = 20
        $this->assertSame(25, $response->json('meta.total'));

        $secondPage = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/notifications?page=2');
        $secondPage->assertStatus(200)->assertJsonCount(5, 'data');
    }

    // --- POST /notifications/{id}/read ---

    public function test_marking_a_notification_read_is_idempotent(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberIn($organization);
        $notification = Notification::factory()->for($organization)->create(['user_id' => $user->id, 'read_at' => null]);

        $first = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/notifications/{$notification->id}/read");
        $first->assertStatus(200);
        $this->assertNotNull($notification->fresh()->read_at);
        $firstReadAt = $notification->fresh()->read_at;

        // Calling it again must not fail and must not change read_at to a later timestamp.
        $second = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/notifications/{$notification->id}/read");
        $second->assertStatus(200);
        $this->assertTrue($firstReadAt->equalTo($notification->fresh()->read_at));
    }

    public function test_marking_another_users_notification_read_returns_404_not_403_to_avoid_leaking_existence(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberIn($organization);
        $attacker = $this->memberIn($organization);
        $notification = Notification::factory()->for($organization)->create(['user_id' => $owner->id, 'read_at' => null]);

        $response = $this->withHeaders($this->authHeader($attacker))
            ->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertStatus(404);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_marking_a_nonexistent_notification_read_also_returns_404(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberIn($organization);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/notifications/999999/read');

        $response->assertStatus(404);
    }

    // --- POST /notifications/read-all ---

    public function test_read_all_marks_every_unread_notification_of_the_current_user_and_no_one_elses(): void
    {
        $organization = Organization::factory()->create();
        $userA = $this->memberIn($organization);
        $userB = $this->memberIn($organization);

        Notification::factory()->for($organization)->count(3)->create(['user_id' => $userA->id, 'read_at' => null]);
        $alreadyRead = Notification::factory()->for($organization)->create(['user_id' => $userA->id, 'read_at' => now()->subHour()]);
        $othersUnread = Notification::factory()->for($organization)->create(['user_id' => $userB->id, 'read_at' => null]);

        $response = $this->withHeaders($this->authHeader($userA))->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(200)->assertJsonPath('data.marked_read', 3);

        $this->assertSame(0, Notification::query()->where('user_id', $userA->id)->whereNull('read_at')->count());
        // The already-read one keeps its original read_at (not re-touched), and userB's remains untouched.
        $this->assertNotNull($alreadyRead->fresh()->read_at);
        $this->assertNull($othersUnread->fresh()->read_at);
    }
}
