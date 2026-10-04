<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCatalogItem;
use App\Models\BoqItem;
use App\Models\BoqTemplate;
use App\Models\BoqTemplateApplication;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
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
 * BOQ Master Catalog + Standard Templates — POST /projects/{project}/boq/template-commit
 * (BoqTemplateCommitService) and the usage-count it feeds (BoqTemplateAdminController::usage()).
 */
class BoqTemplateApplicationLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function manageBoqUser(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [Permissions::MANAGE_BOQ => true]]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active',
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

    private function publishedVersion(Organization $organization, ?BoqTemplate &$template = null): BoqTemplateVersion
    {
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $version = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id]);
        $template->update(['active_version_id' => $version->id]);

        return $version->fresh();
    }

    public function test_commit_logs_one_application_per_distinct_version_with_the_right_item_count(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);

        $templateA = null;
        $versionA = $this->publishedVersion($organization, $templateA);
        $templateB = null;
        $versionB = $this->publishedVersion($organization, $templateB);

        $itemA1 = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $itemA2 = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $itemB1 = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [
                ['catalog_item_id' => $itemA1->id, 'quantity' => 1, 'unit' => 'pcs', 'source_template_id' => $templateA->id, 'source_template_version_id' => $versionA->id],
                ['catalog_item_id' => $itemA2->id, 'quantity' => 2, 'unit' => 'pcs', 'source_template_id' => $templateA->id, 'source_template_version_id' => $versionA->id],
                ['catalog_item_id' => $itemB1->id, 'quantity' => 3, 'unit' => 'pcs', 'source_template_id' => $templateB->id, 'source_template_version_id' => $versionB->id],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('data'));

        $applications = BoqTemplateApplication::where('project_id', $project->id)->get()->keyBy('template_version_id');
        $this->assertCount(2, $applications);
        $this->assertSame(2, $applications[$versionA->id]->item_count);
        $this->assertSame(1, $applications[$versionB->id]->item_count);
        $this->assertSame($organization->id, $applications[$versionA->id]->organization_id);
        $this->assertSame($user->id, $applications[$versionA->id]->applied_by);
    }

    public function test_committed_items_are_stamped_with_full_source_tracking(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $template = null;
        $version = $this->publishedVersion($organization, $template);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $templateItem = BoqTemplateItem::factory()->create(['template_version_id' => $version->id, 'catalog_item_id' => $catalogItem->id]);

        $this->withHeaders($this->authHeader($user))->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [[
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 4,
                'unit' => 'pcs',
                'source_template_id' => $template->id,
                'source_template_version_id' => $version->id,
                'source_template_item_id' => $templateItem->id,
            ]],
        ])->assertStatus(201);

        $boqItem = BoqItem::where('project_id', $project->id)->firstOrFail();
        $this->assertSame($template->id, $boqItem->source_template_id);
        $this->assertSame($version->id, $boqItem->source_template_version_id);
        $this->assertSame($templateItem->id, $boqItem->source_template_item_id);
        $this->assertSame($catalogItem->id, $boqItem->source_catalog_item_id);
    }

    public function test_usage_endpoint_aggregates_applications_across_projects(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $template = null;
        $version = $this->publishedVersion($organization, $template);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $projectOne = $this->projectIn($organization);
        $projectTwo = $this->projectIn($organization);
        $headers = $this->authHeader($user);

        foreach ([$projectOne, $projectTwo] as $project) {
            $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
                'items' => [[
                    'catalog_item_id' => $catalogItem->id, 'quantity' => 1, 'unit' => 'pcs',
                    'source_template_id' => $template->id, 'source_template_version_id' => $version->id,
                ]],
            ])->assertStatus(201);
        }

        $response = $this->withHeaders($headers)->getJson("/api/v1/boq-templates/{$template->id}/usage");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.applications_count'));
        $this->assertCount(2, $response->json('data.recent_applications'));
    }

    public function test_reapplying_a_template_creates_a_second_independent_set_of_items(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $project = $this->projectIn($organization);
        $template = null;
        $version = $this->publishedVersion($organization, $template);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $headers = $this->authHeader($user);

        $payload = [
            'items' => [[
                'catalog_item_id' => $catalogItem->id, 'quantity' => 1, 'unit' => 'pcs',
                'source_template_id' => $template->id, 'source_template_version_id' => $version->id,
            ]],
        ];

        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", $payload)->assertStatus(201);
        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", $payload)->assertStatus(201);

        $this->assertSame(2, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(2, BoqTemplateApplication::where('project_id', $project->id)->count());
    }
}
