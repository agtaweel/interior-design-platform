<?php

namespace Tests\Feature\Snagging;

use App\Models\Client;
use App\Models\Handover;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Role;
use App\Models\Snag;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * BRD "Snagging/Handover" — the key implementation risk the BRD explicitly calls out: "Project
 * cannot be marked complete with unresolved mandatory snags," enforced both via a direct
 * PATCH /projects/{id} {status: completed} and via POST /projects/{id}/handover.
 */
class SnaggingAndHandoverTest extends TestCase
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

    /** @return array{0: Organization, 1: Project, 2: User} */
    private function setUpProject(array $permissions = [
        Permissions::MANAGE_EXECUTION => true,
        Permissions::MANAGE_PROJECTS => true,
    ]): array {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, $permissions);

        return [$organization, $project, $user];
    }

    public function test_store_creates_a_mandatory_open_snag_by_default(): void
    {
        [, $project, $user] = $this->setUpProject();

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/snags",
            ['description' => 'Paint touch-up needed in living room']
        );

        $response->assertStatus(201);
        $this->assertSame('open', $response->json('data.status'));
        $this->assertTrue($response->json('data.is_mandatory'));
    }

    public function test_close_and_reopen_transitions(): void
    {
        [, $project, $user] = $this->setUpProject();
        $snag = Snag::factory()->create(['project_id' => $project->id, 'status' => 'open']);

        $close = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/snags/{$snag->id}/close", ['resolution_notes' => 'Repainted.']);
        $close->assertStatus(200);
        $this->assertSame('closed', $close->json('data.status'));
        $this->assertNotNull($close->json('data.closed_at'));

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/snags/{$snag->id}/close")
            ->assertStatus(409);

        $reopen = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/snags/{$snag->id}/reopen");
        $reopen->assertStatus(200);
        $this->assertSame('open', $reopen->json('data.status'));
        $this->assertNull($reopen->json('data.closed_at'));
    }

    public function test_project_cannot_be_marked_completed_with_an_open_mandatory_snag(): void
    {
        [, $project, $user] = $this->setUpProject();
        Snag::factory()->create(['project_id' => $project->id, 'status' => 'open', 'is_mandatory' => true]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/projects/{$project->id}", ['status' => 'completed'])
            ->assertStatus(409);
    }

    public function test_project_can_be_marked_completed_once_the_mandatory_snag_is_closed(): void
    {
        [, $project, $user] = $this->setUpProject();
        $snag = Snag::factory()->create(['project_id' => $project->id, 'status' => 'open', 'is_mandatory' => true]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/snags/{$snag->id}/close")
            ->assertStatus(200);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/projects/{$project->id}", ['status' => 'completed'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_project_can_be_completed_when_only_a_non_mandatory_snag_is_open(): void
    {
        [, $project, $user] = $this->setUpProject();
        Snag::factory()->create(['project_id' => $project->id, 'status' => 'open', 'is_mandatory' => false]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/projects/{$project->id}", ['status' => 'completed'])
            ->assertStatus(200);
    }

    public function test_handover_store_is_rejected_with_409_while_a_mandatory_snag_is_open(): void
    {
        [, $project, $user] = $this->setUpProject();
        Snag::factory()->create(['project_id' => $project->id, 'status' => 'open', 'is_mandatory' => true]);

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/handover",
            ['handover_date' => now()->toDateString()]
        )->assertStatus(409);
    }

    public function test_handover_store_succeeds_and_is_only_allowed_once(): void
    {
        [, $project, $user] = $this->setUpProject();

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/handover",
            ['handover_date' => now()->toDateString(), 'warranty_period_months' => 12]
        );
        $response->assertStatus(201);
        $this->assertSame($user->id, $response->json('data.approved_by.id'));

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/handover",
            ['handover_date' => now()->toDateString()]
        )->assertStatus(409);
    }

    public function test_handover_pdf_downloads(): void
    {
        [, $project, $user] = $this->setUpProject();
        Handover::factory()->create(['project_id' => $project->id, 'approved_by_user_id' => $user->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->get("/api/v1/projects/{$project->id}/handover/pdf");

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_handover_store_requires_manage_projects_not_manage_execution(): void
    {
        [, $project, $user] = $this->setUpProject([
            Permissions::MANAGE_EXECUTION => true,
            Permissions::MANAGE_PROJECTS => false,
        ]);

        $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/handover",
            ['handover_date' => now()->toDateString()]
        )->assertStatus(403);
    }

    public function test_a_member_of_another_organization_cannot_see_or_close_this_snag(): void
    {
        [, $project] = $this->setUpProject();
        $snag = Snag::factory()->create(['project_id' => $project->id]);

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_EXECUTION => true]);

        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($outsider))
            ->postJson("/api/v1/snags/{$snag->id}/close")
            ->assertStatus(404);
    }
}
