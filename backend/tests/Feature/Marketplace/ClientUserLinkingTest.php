<?php

namespace Tests\Feature\Marketplace;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRD v4 "Client Marketplace" Phase C — POST /inquiries/{conversation}/create-client, the
 * org-side hand-off from an inquiry to a real Client contact record (see
 * ClientUserLinkingService's docblock for the zero/one/many-matches behavior this pins).
 */
class ClientUserLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithManageClients(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [Permissions::MANAGE_CLIENTS => true]]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function staffAuth(User $user, Organization $organization): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'X-Organization-Id' => (string) $organization->id,
        ];
    }

    public function test_creates_a_new_client_when_no_existing_contact_matches_the_email(): void
    {
        $organization = Organization::factory()->create();
        $staff = $this->staffWithManageClients($organization);
        $clientUser = ClientUser::factory()->create(['name' => 'Omar Khaled', 'email' => 'omar@example.com']);
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $response = $this->withHeaders($this->staffAuth($staff, $organization))
            ->postJson("/api/v1/inquiries/{$conversation->id}/create-client");

        $response->assertStatus(200)
            ->assertJsonPath('data.client.name', 'Omar Khaled')
            ->assertJsonPath('data.client.email', 'omar@example.com')
            ->assertJsonPath('data.candidates', null);

        $this->assertDatabaseHas('clients', [
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
            'email' => 'omar@example.com',
        ]);
    }

    public function test_auto_links_a_single_existing_unlinked_client_matching_by_email(): void
    {
        $organization = Organization::factory()->create();
        $staff = $this->staffWithManageClients($organization);
        $clientUser = ClientUser::factory()->create(['email' => 'nadia@example.com']);
        $existingClient = Client::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'nadia@example.com',
            'client_user_id' => null,
        ]);
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $response = $this->withHeaders($this->staffAuth($staff, $organization))
            ->postJson("/api/v1/inquiries/{$conversation->id}/create-client");

        $response->assertStatus(200)->assertJsonPath('data.client.id', $existingClient->id);

        $this->assertSame($clientUser->id, $existingClient->fresh()->client_user_id);
        $this->assertSame(1, Client::query()->where('organization_id', $organization->id)->count());
    }

    public function test_returns_candidates_without_linking_when_multiple_clients_share_the_email(): void
    {
        $organization = Organization::factory()->create();
        $staff = $this->staffWithManageClients($organization);
        $clientUser = ClientUser::factory()->create(['email' => 'dup@example.com']);
        Client::factory()->create(['organization_id' => $organization->id, 'email' => 'dup@example.com']);
        Client::factory()->create(['organization_id' => $organization->id, 'email' => 'dup@example.com']);
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $response = $this->withHeaders($this->staffAuth($staff, $organization))
            ->postJson("/api/v1/inquiries/{$conversation->id}/create-client");

        $response->assertStatus(200)
            ->assertJsonPath('data.client', null)
            ->assertJsonCount(2, 'data.candidates');

        $this->assertNotNull(ClientUser::find($clientUser->id)); // sanity: nothing got deleted
        $this->assertSame(2, Client::query()->where('organization_id', $organization->id)->count());
    }

    public function test_a_member_without_manage_clients_cannot_create_the_client(): void
    {
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => array_fill_keys(Permissions::ALL, false)]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $clientUser = ClientUser::factory()->create();
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $this->withHeaders($this->staffAuth($user, $organization))
            ->postJson("/api/v1/inquiries/{$conversation->id}/create-client")
            ->assertStatus(403);
    }

    public function test_staff_of_another_organization_get_404_for_this_inquiry(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $otherStaff = $this->staffWithManageClients($otherOrganization);
        $clientUser = ClientUser::factory()->create();
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization->id,
            'client_user_id' => $clientUser->id,
        ]);

        $this->withHeaders($this->staffAuth($otherStaff, $otherOrganization))
            ->postJson("/api/v1/inquiries/{$conversation->id}/create-client")
            ->assertStatus(404);
    }
}
