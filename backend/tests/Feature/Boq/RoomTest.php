<?php

namespace Tests\Feature\Boq;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET/POST /projects/{project}/rooms (RoomController, added as a Sprint 2 gap fix per its
 * class docblock — without it, a designer building a BOQ from scratch had no way to add a
 * room). Covers basic CRUD, tenant isolation, and the read/write permission split (GET needs
 * only an active membership, POST needs Permissions::MANAGE_BOQ).
 */
class RoomTest extends TestCase
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

    public function test_full_room_crud_lifecycle_via_the_api(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
        $headers = $this->authHeader($user);

        $emptyList = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/rooms");
        $emptyList->assertStatus(200)->assertJsonCount(0, 'data');

        $create = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/rooms", [
            'name' => 'Master Bedroom',
            'area_m2' => 25.5,
        ]);
        $create->assertStatus(201)
            ->assertJsonPath('data.name', 'Master Bedroom')
            ->assertJsonPath('data.project_id', $project->id);
        $roomId = $create->json('data.id');
        $this->assertDatabaseHas('rooms', ['id' => $roomId, 'project_id' => $project->id, 'name' => 'Master Bedroom']);

        $create2 = $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/rooms", ['name' => 'Kitchen']);
        $create2->assertStatus(201);

        $list = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/rooms");
        $list->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_room_name_is_required(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/rooms", [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_creating_a_room_requires_manage_boq_permission(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/rooms", ['name' => 'Should Fail'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('rooms', ['name' => 'Should Fail']);
    }

    /**
     * Sprint 8 "Permissions hardening" (PROJECT_CONTEXT.md): rooms only exist to organize BOQ
     * line items, so listing them now requires Permissions::MANAGE_BOQ for consistency with the
     * rest of the BOQ-adjacent read surface (BOQ tree, BOQ export, pricing rules/breakdown) —
     * even though a Room itself carries no cost data. A read-only member (no manage_boq) is now
     * forbidden. The privileged-user-succeeds half of this contract is already covered by
     * test_full_room_crud_lifecycle_via_the_api() above (its $user holds
     * Permissions::MANAGE_BOQ and successfully GETs this route twice) — deliberately NOT
     * re-asserted here with a second HTTP-authenticated user in the same test method: see
     * ProposalRbacTest's docblock for the documented Sanctum-guard test-harness quirk this
     * sidesteps (only one user authenticates over real HTTP per test method here).
     */
    public function test_listing_rooms_requires_manage_boq_permission(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        Room::factory()->create(['project_id' => $project->id]);
        $readOnlyUser = $this->memberWithPermissions($organization, []);

        $this->withHeaders($this->authHeader($readOnlyUser))
            ->getJson("/api/v1/projects/{$project->id}/rooms")
            ->assertStatus(403);
    }

    public function test_rooms_are_tenant_isolated(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_BOQ => true]);
        $projectB = $this->projectIn($orgB);
        Room::factory()->create(['project_id' => $projectB->id, 'name' => 'Org B Room']);

        $headersA = $this->authHeader($userA);

        $this->withHeaders($headersA)->getJson("/api/v1/projects/{$projectB->id}/rooms")->assertStatus(404);

        $this->withHeaders($headersA)
            ->postJson("/api/v1/projects/{$projectB->id}/rooms", ['name' => 'Intruder Room'])
            ->assertStatus(404);
        $this->assertDatabaseMissing('rooms', ['name' => 'Intruder Room']);
    }
}
