<?php

namespace Tests\Feature\Projects;

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
 * GET /clients and GET /projects both paginate (Laravel's paginate()) and support a `q` search
 * term; GET /projects additionally filters by `status` and `client_id`. ClientPropertyProjectApiTest
 * only ever creates a single record per test, so pagination math and the interaction between
 * search/filters and pagination were never actually exercised. This file does that.
 */
class ListingPaginationAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fullAccessUser(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [
            Permissions::MANAGE_CLIENTS => true,
            Permissions::MANAGE_PROJECTS => true,
        ]]);
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

    public function test_clients_index_paginates_and_respects_per_page(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        Client::factory()->count(25)->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/clients?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 3);

        $page2 = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/clients?per_page=10&page=2');
        $page2->assertStatus(200)->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 2);

        $page3 = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/clients?per_page=10&page=3');
        $page3->assertStatus(200)->assertJsonCount(5, 'data');
    }

    public function test_clients_index_per_page_is_capped_at_100(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        Client::factory()->count(3)->create(['organization_id' => $organization->id]);

        // 500 exceeds the documented max:100 rule in IndexClientsRequest.
        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/clients?per_page=500')
            ->assertStatus(422);
    }

    public function test_clients_index_search_matches_name_phone_or_email_case_insensitively(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $target = Client::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Nour El Din',
            'phone' => '+201009998888',
            'email' => 'nour@example.com',
        ]);
        Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Someone Else']);

        $byName = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/clients?q=nour el');
        $byName->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);

        $byPhone = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/clients?q=9998888');
        $byPhone->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);

        $byEmailUppercase = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/clients?q=NOUR@EXAMPLE');
        $byEmailUppercase->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_clients_index_search_with_no_matches_returns_an_empty_page_not_an_error(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Someone']);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/clients?q=nonexistent-needle')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_projects_index_paginates_and_filters_by_status(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        Project::factory()->count(3)->create([
            'organization_id' => $organization->id, 'client_id' => $client->id, 'status' => 'draft',
        ]);
        Project::factory()->count(2)->create([
            'organization_id' => $organization->id, 'client_id' => $client->id, 'status' => 'active',
        ]);

        $draftOnly = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/projects?status=draft');
        $draftOnly->assertStatus(200)->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);

        $activeOnly = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/projects?status=active');
        $activeOnly->assertStatus(200)->assertJsonCount(2, 'data');

        $all = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/projects');
        $all->assertStatus(200)->assertJsonPath('meta.total', 5);
    }

    public function test_projects_index_rejects_an_invalid_status_filter(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/projects?status=not_a_real_status')
            ->assertStatus(422);
    }

    public function test_projects_index_filters_by_client_id_scoped_to_the_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $clientA = Client::factory()->create(['organization_id' => $organization->id]);
        $clientB = Client::factory()->create(['organization_id' => $organization->id]);
        $projectA = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $clientA->id]);
        Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $clientB->id]);

        $response = $this->withHeaders($this->authHeader($user))->getJson("/api/v1/projects?client_id={$clientA->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $projectA->id);
    }

    public function test_projects_index_pagination_does_not_leak_across_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->fullAccessUser($orgA);
        $clientA = Client::factory()->create(['organization_id' => $orgA->id]);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        Project::factory()->count(2)->create(['organization_id' => $orgA->id, 'client_id' => $clientA->id]);
        Project::factory()->count(5)->create(['organization_id' => $orgB->id, 'client_id' => $clientB->id]);

        $response = $this->withHeaders($this->authHeader($userA))->getJson('/api/v1/projects');

        $response->assertStatus(200)->assertJsonPath('meta.total', 2);
    }
}
