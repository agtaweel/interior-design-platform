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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documents tab attachments (ProjectMediaController, backed by Spatie Media Library —
 * Project::MEDIA_COLLECTIONS). Mirrors PaymentReceiptUploadTest's structure/rationale:
 * Storage::fake('local') intercepts the real disk, and fixtures use genuine magic-byte content
 * (a real 1x1 PNG) since StoreProjectMediaRequest's `mimes:` rule guesses MIME from actual file
 * bytes, not the client-supplied extension.
 */
class ProjectMediaTest extends TestCase
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

    /** @return array{0: Project, 1: User, 2: Organization} */
    private function setUpProject(array $permissions = [Permissions::MANAGE_PROJECTS => true]): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, $permissions);

        return [$project, $user, $organization];
    }

    private function pngFile(string $name = 'design.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64));
    }

    public function test_uploading_to_each_collection_succeeds_and_leaks_no_raw_disk_path(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject();

        foreach (Project::MEDIA_COLLECTIONS as $collection) {
            $response = $this->withHeaders($this->authHeader($user))->post(
                "/api/v1/projects/{$project->id}/media",
                ['collection' => $collection, 'caption' => 'A caption', 'file' => $this->pngFile()]
            );

            $response->assertStatus(201);
            $this->assertSame($collection, $response->json('data.collection'));
            $this->assertSame('A caption', $response->json('data.caption'));
            $this->assertSame($user->name, $response->json('data.uploaded_by'));
            $this->assertArrayNotHasKey('path', $response->json('data'));
            $this->assertArrayNotHasKey('disk', $response->json('data'));
        }
    }

    public function test_index_lists_media_across_all_collections_newest_first(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject();

        $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile('a.png')]
        )->assertStatus(201);

        $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'final_pictures', 'file' => $this->pngFile('b.png')]
        )->assertStatus(201);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}/media");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_gallery_index_lists_media_across_every_project_in_the_organization(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $projectA = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $projectB = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);

        $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$projectA->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile('a.png')]
        )->assertStatus(201);

        $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$projectB->id}/media",
            ['collection' => 'final_pictures', 'file' => $this->pngFile('b.png')]
        )->assertStatus(201);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/media');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $projectIdsInResponse = collect($response->json('data'))->pluck('project.id')->sort()->values()->all();
        $expectedIds = collect([$projectA->id, $projectB->id])->sort()->values()->all();
        $this->assertSame($expectedIds, $projectIdsInResponse);
    }

    public function test_gallery_index_filters_by_collection_and_project_id(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $projectA = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $projectB = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $user = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => true]);

        $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$projectA->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile('a.png')]
        )->assertStatus(201);

        $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$projectB->id}/media",
            ['collection' => 'final_pictures', 'file' => $this->pngFile('b.png')]
        )->assertStatus(201);

        $byCollection = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/media?collection=designs')
            ->assertStatus(200);
        $this->assertCount(1, $byCollection->json('data'));
        $this->assertSame('designs', $byCollection->json('data.0.collection'));

        $byProject = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/media?project_id={$projectB->id}")
            ->assertStatus(200);
        $this->assertCount(1, $byProject->json('data'));
        $this->assertSame($projectB->id, $byProject->json('data.0.project.id'));
    }

    public function test_gallery_index_never_leaks_another_organizations_media(): void
    {
        Storage::fake('local');
        [$projectA, $userA] = $this->setUpProject();
        $this->withHeaders($this->authHeader($userA))->post(
            "/api/v1/projects/{$projectA->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile('a.png')]
        )->assertStatus(201);

        // Seed org B's media directly via the model rather than a second authenticated HTTP
        // POST from a different user. This codebase's Sanctum-token test harness has a
        // documented quirk (see ProposalRbacTest::test_read_only_member_can_list_and_view_...'s
        // docblock for the full write-up + confirmed-by-instrumentation details) where two
        // real HTTP requests from different users against the same mutating route within one
        // test method can have the second resolve as the first user internally, even with
        // Auth::forgetGuards() called between them — reproduced here identically when
        // attempted (a second POST as $userB kept resolving as $userA). Sidestepping it this
        // way, exactly as that precedent does, still fully exercises the thing actually under
        // test — gallery tenant isolation — without tripping the harness artifact.
        $orgB = Organization::factory()->create();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        $projectB = Project::factory()->create(['organization_id' => $orgB->id, 'client_id' => $clientB->id]);
        $projectB->addMedia($this->pngFile('b.png'))->toMediaCollection('designs');

        $response = $this->withHeaders($this->authHeader($userA))->getJson('/api/v1/media');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($projectA->id, $response->json('data.0.project.id'));
    }

    public function test_get_media_file_streams_the_correct_bytes_and_content_type(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject();

        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile()]
        )->assertStatus(201);

        $response = $this->withHeaders($this->authHeader($user))
            ->get('/api/v1/media/'.$create->json('data.id').'/file');

        $response->assertStatus(200);
        $this->assertStringContainsString('image/png', $response->headers->get('content-type'));
        $this->assertSame(base64_decode(self::PNG_BASE64), $response->streamedContent());
    }

    public function test_destroy_removes_the_media_row_and_it_404s_afterwards(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject();

        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'process', 'file' => $this->pngFile()]
        )->assertStatus(201);
        $mediaId = $create->json('data.id');

        $this->withHeaders($this->authHeader($user))
            ->delete("/api/v1/media/{$mediaId}")
            ->assertStatus(204);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/media/{$mediaId}/file")
            ->assertStatus(404);
    }

    public function test_upload_without_manage_projects_permission_is_rejected_with_403(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject([Permissions::MANAGE_PROJECTS => false]);

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile()]
        );

        $response->assertStatus(403);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_destroy_without_manage_projects_permission_is_rejected_with_403(): void
    {
        Storage::fake('local');
        [$project, $owner, $organization] = $this->setUpProject();
        $create = $this->withHeaders($this->authHeader($owner))->post(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile()]
        )->assertStatus(201);

        $viewer = $this->memberWithPermissions($organization, [Permissions::MANAGE_PROJECTS => false]);

        // Required before switching authenticated users mid-test — Sanctum's guard memoizes
        // the first resolved user for the lifetime of the test's application container (see
        // PricingTenantIsolationTest's identical precedent/rationale). Not needed by any other
        // test in this file since they only ever authenticate as a single user.
        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($viewer))
            ->deleteJson('/api/v1/media/'.$create->json('data.id'))
            ->assertStatus(403);
    }

    public function test_uploading_an_invalid_collection_name_is_rejected_with_422(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject();

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'not_a_real_collection', 'file' => $this->pngFile()]
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('collection', $response->json('error.details'));
    }

    public function test_a_member_of_another_organization_cannot_see_or_upload_to_this_project(): void
    {
        Storage::fake('local');
        [$project] = $this->setUpProject();
        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_PROJECTS => true]);

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/projects/{$project->id}/media")
            ->assertStatus(404);

        $this->withHeaders($this->authHeader($outsider))->postJson(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile()]
        )->assertStatus(404);
    }

    public function test_a_member_of_another_organization_cannot_fetch_or_delete_this_media(): void
    {
        Storage::fake('local');
        [$project, $user] = $this->setUpProject();
        $create = $this->withHeaders($this->authHeader($user))->post(
            "/api/v1/projects/{$project->id}/media",
            ['collection' => 'designs', 'file' => $this->pngFile()]
        )->assertStatus(201);
        $mediaId = $create->json('data.id');

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_PROJECTS => true]);

        // Required before switching authenticated users mid-test — see the identical
        // Auth::forgetGuards() precedent/rationale in the destroy-permission test above.
        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/media/{$mediaId}/file")
            ->assertStatus(404);

        $this->withHeaders($this->authHeader($outsider))
            ->deleteJson("/api/v1/media/{$mediaId}")
            ->assertStatus(404);

        Auth::forgetGuards();

        // Still fetchable by the actual owning organization — proves the 404 above is a real
        // tenant-isolation check, not the media row having actually been deleted.
        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/media/{$mediaId}/file")
            ->assertStatus(200);
    }
}
