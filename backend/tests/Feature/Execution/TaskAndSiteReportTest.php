<?php

namespace Tests\Feature\Execution;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\SiteReport;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRD "Execution": tasks (S16) + site reports (S17), including photo attachments via the
 * shared Spatie Media Library infrastructure and the site-report PDF export.
 */
class TaskAndSiteReportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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
    private function setUpProject(array $permissions = [Permissions::MANAGE_EXECUTION => true]): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, $permissions);

        return [$organization, $project, $user];
    }

    public function test_store_task_creates_it_with_a_photo(): void
    {
        Storage::fake('local');
        [, $project, $user] = $this->setUpProject();

        $response = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/tasks",
            [
                'title' => 'Install kitchen cabinets',
                'status' => 'in_progress',
                'photos' => [UploadedFile::fake()->createWithContent('progress.png', base64_decode(self::PNG_BASE64))],
            ]
        );

        $response->assertStatus(201);
        $this->assertSame('in_progress', $response->json('data.status'));
        $this->assertCount(1, $response->json('data.photos'));
    }

    public function test_update_task_moves_it_through_the_kanban_statuses(): void
    {
        [, $project, $user] = $this->setUpProject();
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'status' => 'todo']);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/tasks/{$task->id}", ['status' => 'done']);

        $response->assertStatus(200);
        $this->assertSame('done', $response->json('data.status'));
    }

    public function test_index_lists_tasks_ordered_by_sort_order(): void
    {
        [, $project, $user] = $this->setUpProject();
        ProjectTask::factory()->create(['project_id' => $project->id, 'sort_order' => 2, 'title' => 'Second']);
        ProjectTask::factory()->create(['project_id' => $project->id, 'sort_order' => 1, 'title' => 'First']);

        $response = $this->withHeaders($this->authHeader($user))->getJson("/api/v1/projects/{$project->id}/tasks");

        $response->assertStatus(200);
        $this->assertSame('First', $response->json('data.0.title'));
    }

    public function test_task_mutations_without_manage_execution_permission_are_rejected_with_403(): void
    {
        [, $project, $user] = $this->setUpProject([Permissions::MANAGE_EXECUTION => false]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'x'])
            ->assertStatus(403);
    }

    public function test_store_site_report_creates_it_and_pdf_downloads(): void
    {
        Storage::fake('local');
        [, $project, $user] = $this->setUpProject();

        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/site-reports",
            [
                'report_date' => now()->toDateString(),
                'work_done' => 'Poured the master bathroom screed.',
                'issues' => 'Delivery of tiles delayed by two days.',
                'photos' => [UploadedFile::fake()->createWithContent('site.png', base64_decode(self::PNG_BASE64))],
            ]
        );

        $create->assertStatus(201);
        $this->assertSame($user->id, $create->json('data.reported_by.id'));
        $this->assertCount(1, $create->json('data.photos'));

        $pdf = $this->withHeaders($this->authHeader($user))
            ->get('/api/v1/site-reports/'.$create->json('data.id').'/pdf');

        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_execution_media_streams_a_task_photo_and_rejects_cross_tenant_access(): void
    {
        Storage::fake('local');
        [$organization, $project, $user] = $this->setUpProject();
        $task = ProjectTask::factory()->create(['project_id' => $project->id]);
        $task->addMediaFromString(base64_decode(self::PNG_BASE64))
            ->usingFileName('photo.png')
            ->toMediaCollection(ProjectTask::PHOTOS_COLLECTION);
        $mediaId = $task->media()->first()->id;

        $this->withHeaders($this->authHeader($user))
            ->get("/api/v1/execution-media/{$mediaId}/file")
            ->assertStatus(200);

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_EXECUTION => true]);

        // Required before switching authenticated users mid-test — see the documented Sanctum
        // guard-memoization quirk (PricingTenantIsolationTest's Auth::forgetGuards() precedent
        // / ProjectMediaController tests' identical precaution).
        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/execution-media/{$mediaId}/file")
            ->assertStatus(404);
    }

    public function test_a_member_of_another_organization_cannot_see_this_projects_tasks_or_reports(): void
    {
        [, $project] = $this->setUpProject();
        ProjectTask::factory()->create(['project_id' => $project->id]);
        SiteReport::factory()->create(['project_id' => $project->id]);

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_EXECUTION => true]);

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/projects/{$project->id}/tasks")
            ->assertStatus(404);

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/projects/{$project->id}/site-reports")
            ->assertStatus(404);
    }
}
