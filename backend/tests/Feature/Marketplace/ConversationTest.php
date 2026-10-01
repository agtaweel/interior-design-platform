<?php

namespace Tests\Feature\Marketplace;

use App\Models\ClientUser;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRD v4 "Client Marketplace" Phase B — chat between a marketplace ClientUser and an
 * Organization's staff. Covers both sides of the thread (ClientConversationController,
 * OrganizationInquiryController) plus the two leak surfaces that matter: a client reaching
 * another client's conversation, and staff of one organization reaching another organization's
 * inquiries.
 */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_start_a_conversation_with_a_listed_organization(): void
    {
        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);
        $clientUser = ClientUser::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$clientUser->createToken('t')->plainTextToken)
            ->postJson('/api/v1/client/conversations', ['organization_id' => $organization->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('conversations', [
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);
    }

    public function test_client_cannot_start_a_conversation_with_an_unlisted_organization(): void
    {
        $organization = Organization::factory()->create();
        $clientUser = ClientUser::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$clientUser->createToken('t')->plainTextToken)
            ->postJson('/api/v1/client/conversations', ['organization_id' => $organization->id])
            ->assertStatus(404);
    }

    public function test_starting_a_conversation_twice_returns_the_same_thread(): void
    {
        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);
        $clientUser = ClientUser::factory()->create();
        $token = 'Bearer '.$clientUser->createToken('t')->plainTextToken;

        $first = $this->withHeader('Authorization', $token)
            ->postJson('/api/v1/client/conversations', ['organization_id' => $organization->id])
            ->json('data.id');

        $second = $this->withHeader('Authorization', $token)
            ->postJson('/api/v1/client/conversations', ['organization_id' => $organization->id])
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::count());
    }

    public function test_client_posting_a_message_notifies_staff_with_manage_clients(): void
    {
        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);
        $clientUser = ClientUser::factory()->create(['name' => 'Laila Farouk']);

        $role = Role::factory()->create([
            'organization_id' => null,
            'name' => 'Owner',
            'permissions_json' => array_fill_keys(Permissions::ALL, true),
        ]);
        $staff = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $clientToken = 'Bearer '.$clientUser->createToken('t')->plainTextToken;
        $conversationId = $this->withHeader('Authorization', $clientToken)
            ->postJson('/api/v1/client/conversations', ['organization_id' => $organization->id])
            ->json('data.id');

        $response = $this->withHeader('Authorization', $clientToken)
            ->postJson("/api/v1/client/conversations/{$conversationId}/messages", [
                'body' => 'Hi, I would love a quote for my apartment.',
            ]);

        $response->assertStatus(201)->assertJsonPath('data.sender_type', 'client');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'sender_type' => 'client',
            'body' => 'Hi, I would love a quote for my apartment.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'type' => 'new_inquiry_message',
        ]);
    }

    public function test_a_client_cannot_read_or_post_into_another_clients_conversation(): void
    {
        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);
        $owner = ClientUser::factory()->create();
        $intruder = ClientUser::factory()->create();

        $conversationId = $this->withHeader('Authorization', 'Bearer '.$owner->createToken('t')->plainTextToken)
            ->postJson('/api/v1/client/conversations', ['organization_id' => $organization->id])
            ->json('data.id');

        // Auth::forgetGuards() before switching authenticated users mid-test — see
        // laravel-sanctum-test-user-switch-quirk memory: without this, the second HTTP call's
        // $request->user() can resolve as the FIRST user's token even with a fresh Bearer header,
        // which would make this test pass for the wrong reason (or fail as a false leak).
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $intruderToken = 'Bearer '.$intruder->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', $intruderToken)
            ->getJson("/api/v1/client/conversations/{$conversationId}/messages")
            ->assertStatus(404);

        $this->withHeader('Authorization', $intruderToken)
            ->postJson("/api/v1/client/conversations/{$conversationId}/messages", ['body' => 'sneaky'])
            ->assertStatus(404);
    }

    public function test_staff_can_list_and_reply_to_inquiries_for_their_own_organization(): void
    {
        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);
        $clientUser = ClientUser::factory()->create();

        $role = Role::factory()->create([
            'organization_id' => null,
            'name' => 'Owner',
            'permissions_json' => array_fill_keys(Permissions::ALL, true),
        ]);
        $staff = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $staffAuth = $this->withHeader('Authorization', 'Bearer '.$staff->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id);

        $staffAuth->getJson('/api/v1/inquiries')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client.id', $clientUser->id);

        $staffAuth->postJson("/api/v1/inquiries/{$conversation->id}/messages", ['body' => 'Thanks for reaching out!'])
            ->assertStatus(201)
            ->assertJsonPath('data.sender_type', 'org_member');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'org_member',
            'body' => 'Thanks for reaching out!',
        ]);
    }

    public function test_staff_of_another_organization_cannot_see_or_reply_to_this_inquiry(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $clientUser = ClientUser::factory()->create();

        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $role = Role::factory()->create([
            'organization_id' => null,
            'name' => 'Owner',
            'permissions_json' => array_fill_keys(Permissions::ALL, true),
        ]);
        $otherStaff = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $otherOrganization->id,
            'user_id' => $otherStaff->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $otherAuth = $this->withHeader('Authorization', 'Bearer '.$otherStaff->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $otherOrganization->id);

        $otherAuth->getJson('/api/v1/inquiries')->assertStatus(200)->assertJsonCount(0, 'data');
        $otherAuth->getJson("/api/v1/inquiries/{$conversation->id}")->assertStatus(404);
        $otherAuth->postJson("/api/v1/inquiries/{$conversation->id}/messages", ['body' => 'sneaky'])->assertStatus(404);
    }

    public function test_a_member_without_manage_clients_cannot_reply_to_an_inquiry(): void
    {
        $organization = Organization::factory()->create();
        $clientUser = ClientUser::factory()->create();
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $role = Role::factory()->create([
            'organization_id' => null,
            'name' => 'Designer',
            'permissions_json' => array_fill_keys(Permissions::ALL, false),
        ]);
        $staff = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $staff->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$staff->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->postJson("/api/v1/inquiries/{$conversation->id}/messages", ['body' => 'trying anyway'])
            ->assertStatus(403);
    }
}
