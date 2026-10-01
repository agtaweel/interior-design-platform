<?php

namespace Tests\Feature\Marketplace;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationProfile;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BRD v4 "Client Marketplace" — the regression test PublicOrganizationMediaController's docblock
 * calls for: Spatie's `media` table is one polymorphic table shared by `Project` (private design
 * files) and `Organization` (public `portfolio`). This test attaches a real file to a PRIVATE
 * project and asserts it can never be fetched through the PUBLIC organization media endpoint —
 * the exact leak a bare `Media::find()` shortcut would reintroduce — then confirms the happy
 * path (an organization's own listed portfolio file) still works.
 */
class OrganizationPortfolioMediaLeakTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_a_private_project_media_file_cannot_be_fetched_through_the_public_organization_endpoint(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);

        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
        ]);

        $file = UploadedFile::fake()->createWithContent('design.png', base64_decode(self::PNG_BASE64));
        $media = $project->addMedia($file)->toMediaCollection('designs');

        $this->getJson("/api/v1/public/marketplace/organizations/{$organization->id}/media/{$media->id}/file")
            ->assertStatus(404);
    }

    public function test_a_listed_organizations_own_portfolio_file_is_publicly_reachable(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);

        $file = UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG_BASE64));
        $media = $organization->addMedia($file)->toMediaCollection('portfolio');

        $this->get("/api/v1/public/marketplace/organizations/{$organization->id}/media/{$media->id}/file")
            ->assertStatus(200);
    }

    public function test_a_portfolio_file_belonging_to_an_unlisted_organization_is_not_reachable(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        // Deliberately no OrganizationProfile at all — never opted in.

        $file = UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG_BASE64));
        $media = $organization->addMedia($file)->toMediaCollection('portfolio');

        $this->getJson("/api/v1/public/marketplace/organizations/{$organization->id}/media/{$media->id}/file")
            ->assertStatus(404);
    }

    public function test_staff_can_upload_and_delete_portfolio_media(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $role = Role::factory()->create([
            'organization_id' => null,
            'permissions_json' => array_fill_keys(Permissions::ALL, true),
        ]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'X-Organization-Id' => (string) $organization->id,
        ];

        $file = UploadedFile::fake()->createWithContent('cover.png', base64_decode(self::PNG_BASE64));

        $uploadResponse = $this->withHeaders($headers)
            ->post("/api/v1/organizations/{$organization->id}/portfolio", ['file' => $file]);

        $uploadResponse->assertStatus(201);
        $mediaId = $uploadResponse->json('data.id');

        $this->withHeaders($headers)
            ->delete("/api/v1/organizations/{$organization->id}/portfolio/{$mediaId}")
            ->assertStatus(204);
    }
}
