<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Property;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 1 Definition-of-Done: "All commercial mutations are audited." OrganizationMemberTest
 * already proves audit rows exist for member invite/update; this file proves the same wiring
 * (App\Models\Concerns\Auditable, applied to Client/Property/Project/ProjectService) actually
 * fires with correct organization scoping, actor attribution, and before/after JSON content —
 * not just that a row shows up. project_members is NOT covered because ProjectMember does not
 * use the Auditable trait (see finding reported to backend-api-engineer/db-architect).
 */
class AuditLogTest extends TestCase
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

    public function test_creating_a_client_writes_an_audit_log_with_after_snapshot_and_actor(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/v1/clients', [
            'name' => 'Audited Client',
            'phone' => '+201001234567',
        ]);
        $response->assertStatus(201);
        $clientId = $response->json('data.id');

        $log = AuditLog::where('entity_type', 'Client')->where('entity_id', $clientId)->first();

        $this->assertNotNull($log, 'Expected an audit_logs row for the client creation.');
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame('created', $log->action);
        $this->assertNull($log->before_json);
        $this->assertSame('Audited Client', $log->after_json['name']);
        $this->assertArrayNotHasKey('password', $log->after_json);
    }

    public function test_updating_a_client_writes_an_audit_log_with_only_changed_fields_before_and_after(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Original Name',
            'notes' => null,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/clients/{$client->id}", ['notes' => 'Prefers WhatsApp.'])
            ->assertStatus(200);

        $log = AuditLog::where('entity_type', 'Client')
            ->where('entity_id', $client->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($user->id, $log->actor_user_id);

        // Only the actually-changed field should appear — name (unchanged) must not leak in.
        $this->assertArrayHasKey('notes', $log->after_json);
        $this->assertSame('Prefers WhatsApp.', $log->after_json['notes']);
        $this->assertArrayNotHasKey('name', $log->after_json);
        $this->assertArrayHasKey('notes', $log->before_json);
        $this->assertNull($log->before_json['notes']);
    }

    public function test_updating_a_client_with_no_actual_field_changes_writes_no_audit_log(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id, 'name' => 'Same Name']);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/clients/{$client->id}", ['name' => 'Same Name'])
            ->assertStatus(200);

        $this->assertSame(
            0,
            AuditLog::where('entity_type', 'Client')->where('entity_id', $client->id)->where('action', 'updated')->count()
        );
    }

    public function test_creating_a_project_writes_an_audit_log_scoped_to_the_project_organization(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/v1/projects', [
            'client_id' => $client->id,
            'name' => 'Audited Project',
        ]);
        $response->assertStatus(201);
        $projectId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'Project',
            'entity_id' => $projectId,
            'action' => 'created',
        ]);
    }

    public function test_creating_a_property_writes_an_audit_log(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->postJson("/api/v1/clients/{$client->id}/properties", [
            'type' => 'villa',
        ]);
        $response->assertStatus(201);
        $propertyId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'Property',
            'entity_id' => $propertyId,
            'action' => 'created',
        ]);
    }

    public function test_adding_a_project_service_writes_an_audit_log_resolved_via_the_parent_project(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);

        $response = $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/services", [
            'service_type' => 'design',
            'pricing_method' => 'fixed',
            'price' => 10000,
        ]);
        $response->assertStatus(201);

        // ProjectService has no organization_id column of its own — Auditable must resolve it
        // via auditOrganizationId() -> $this->project->organization_id (see ProjectService model).
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'entity_type' => 'ProjectService',
            'action' => 'created',
        ]);
    }
}
