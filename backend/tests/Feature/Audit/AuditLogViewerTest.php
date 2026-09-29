<?php

namespace Tests\Feature\Audit;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Platform Readiness Review finding #05 "Audit log viewer" — GET /audit-logs
 * (AuditLogController::index()). Every row asserted here is produced by the SAME
 * App\Models\Concerns\Auditable wiring AuditLogTest.php already proves writes correctly; this
 * file proves the read side: the MANAGE_ORGANIZATION gate, tenant isolation (via AuditLog's
 * BelongsToOrganization scope), and the entity_type/action filters.
 */
class AuditLogViewerTest extends TestCase
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

    public function test_a_member_without_manage_organization_is_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_CLIENTS => true]);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/audit-logs')
            ->assertStatus(403);
    }

    public function test_a_manage_organization_member_sees_audit_rows_from_their_own_org_only(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->memberWithPermissions($orgA, [Permissions::MANAGE_ORGANIZATION => true, Permissions::MANAGE_CLIENTS => true]);
        $userB = $this->memberWithPermissions($orgB, [Permissions::MANAGE_ORGANIZATION => true, Permissions::MANAGE_CLIENTS => true]);

        $this->withHeaders($this->authHeader($userA))
            ->postJson('/api/v1/clients', ['name' => 'Org A Client'])
            ->assertStatus(201);

        // Sanctum's guard memoizes the resolved user per test method (see the
        // laravel-sanctum-test-user-switch-quirk memory) — forgetGuards() before switching back
        // to userA avoids the second request silently resolving as userB.
        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($userB))
            ->postJson('/api/v1/clients', ['name' => 'Org B Client'])
            ->assertStatus(201);

        Auth::forgetGuards();

        $response = $this->withHeaders($this->authHeader($userA))
            ->getJson('/api/v1/audit-logs')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('after.name');
        $this->assertTrue($names->contains('Org A Client'));
        $this->assertFalse($names->contains('Org B Client'));
    }

    public function test_response_includes_actor_action_and_before_after_snapshots(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_ORGANIZATION => true, Permissions::MANAGE_CLIENTS => true]);

        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Original', 'notes' => null]);
        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/clients/{$client->id}", ['notes' => 'Updated note.'])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/audit-logs?entity_type=Client&action=updated')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('entity_id', $client->id);
        $this->assertNotNull($row);
        $this->assertSame('updated', $row['action']);
        $this->assertSame($user->id, $row['actor']['id']);
        $this->assertSame($user->name, $row['actor']['name']);
        $this->assertSame('Updated note.', $row['after']['notes']);
        $this->assertNull($row['before']['notes']);
    }

    public function test_results_are_paginated_newest_first(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_ORGANIZATION => true, Permissions::MANAGE_CLIENTS => true]);

        $this->withHeaders($this->authHeader($user))->postJson('/api/v1/clients', ['name' => 'First'])->assertStatus(201);
        $this->withHeaders($this->authHeader($user))->postJson('/api/v1/clients', ['name' => 'Second'])->assertStatus(201);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/audit-logs?entity_type=Client&per_page=1')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('Second', $response->json('data.0.after.name'));
    }
}
