<?php

namespace Tests\Feature\Boq;

use App\Models\BoqCatalogItem;
use App\Models\BoqTemplate;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BOQ Master Catalog + Standard Templates — BoqTemplateAdminController's version lifecycle:
 * draft creation (optionally copying an existing version), the draft-only item-mutation rule,
 * and publish's exclusivity (demotes any other published sibling, sets active_version_id).
 */
class BoqTemplateVersioningTest extends TestCase
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

    private function manageBoqUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    public function test_a_new_draft_version_copied_from_an_existing_one_is_a_full_independent_copy(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $v1 = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id, 'version_number' => 1]);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        BoqTemplateItem::factory()->create(['template_version_id' => $v1->id, 'catalog_item_id' => $catalogItem->id, 'default_quantity' => 5]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/boq-templates/{$template->id}/versions", ['copy_from_version_id' => $v1->id]);

        $response->assertStatus(201);
        $v2Id = $response->json('data.id');
        $this->assertSame(2, $response->json('data.version_number'));

        $v2 = BoqTemplateVersion::find($v2Id);
        $this->assertCount(1, $v2->items);
        $copiedItem = $v2->items->first();
        $this->assertSame('5.00', (string) $copiedItem->default_quantity);
        $this->assertNotSame($copiedItem->id, $v1->items->first()->id);

        // Editing the copy must never touch the original version's item.
        $copiedItem->update(['default_quantity' => 999]);
        $this->assertSame('5.00', (string) $v1->items->first()->fresh()->default_quantity);
    }

    public function test_publishing_a_version_demotes_the_previously_published_sibling_and_sets_active_version(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $v1 = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id, 'version_number' => 1]);
        $template->update(['active_version_id' => $v1->id]);
        $v2 = BoqTemplateVersion::factory()->create(['template_id' => $template->id, 'version_number' => 2]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/boq-templates/{$template->id}/versions/{$v2->id}/publish");

        $response->assertStatus(200);
        $this->assertSame(BoqTemplateVersion::STATUS_PUBLISHED, $v2->fresh()->status);
        $this->assertSame(BoqTemplateVersion::STATUS_ARCHIVED, $v1->fresh()->status);
        $this->assertSame($v2->id, $template->fresh()->active_version_id);
    }

    public function test_a_published_versions_items_reject_edits_and_new_additions(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $version = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id]);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        $item = BoqTemplateItem::factory()->create(['template_version_id' => $version->id, 'catalog_item_id' => $catalogItem->id]);
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->postJson("/api/v1/boq-templates/{$template->id}/versions/{$version->id}/items", ['catalog_item_id' => $catalogItem->id])
            ->assertStatus(409);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/boq-templates/items/{$item->id}", ['default_quantity' => 42])
            ->assertStatus(404);
        $this->assertNotSame('42.00', (string) $item->fresh()->default_quantity);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/boq-templates/items/{$item->id}")
            ->assertStatus(404);
        $this->assertNotNull($item->fresh());
    }

    public function test_a_project_applied_template_stays_pinned_to_its_original_version_after_a_new_version_publishes(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $v1 = BoqTemplateVersion::factory()->published()->create(['template_id' => $template->id, 'version_number' => 1]);
        $template->update(['active_version_id' => $v1->id]);

        $project = $this->projectIn($organization);
        $catalogItem = BoqCatalogItem::factory()->create(['organization_id' => $organization->id]);
        BoqTemplateItem::factory()->create(['template_version_id' => $v1->id, 'catalog_item_id' => $catalogItem->id, 'is_required' => true]);

        $headers = $this->authHeader($user);
        $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/boq/template-commit", [
            'items' => [[
                'catalog_item_id' => $catalogItem->id,
                'quantity' => 1,
                'unit' => 'pcs',
                'source_template_id' => $template->id,
                'source_template_version_id' => $v1->id,
            ]],
        ])->assertStatus(201);

        $boqItem = \App\Models\BoqItem::where('project_id', $project->id)->first();
        $this->assertSame($v1->id, $boqItem->source_template_version_id);

        $v2 = BoqTemplateVersion::factory()->create(['template_id' => $template->id, 'version_number' => 2]);
        $this->withHeaders($headers)->postJson("/api/v1/boq-templates/{$template->id}/versions/{$v2->id}/publish")->assertStatus(200);

        $this->assertSame($v1->id, $boqItem->fresh()->source_template_version_id);
        $this->assertSame($v2->id, $template->fresh()->active_version_id);
    }

    public function test_read_only_member_cannot_create_or_publish_versions_but_can_read_templates(): void
    {
        $organization = Organization::factory()->create();
        $readOnlyUser = $this->memberWithPermissions($organization, []);
        $template = BoqTemplate::factory()->create(['organization_id' => $organization->id]);
        $version = BoqTemplateVersion::factory()->create(['template_id' => $template->id]);
        $headers = $this->authHeader($readOnlyUser);

        $this->withHeaders($headers)->getJson("/api/v1/boq-templates/{$template->id}")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/v1/boq-templates/{$template->id}/versions")->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/v1/boq-templates/{$template->id}/versions/{$version->id}/publish")->assertStatus(403);
    }

    public function test_tenant_mount_cannot_mutate_a_global_system_template(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->manageBoqUser($organization);
        $systemTemplate = BoqTemplate::factory()->system()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/boq-templates/{$systemTemplate->id}/versions")
            ->assertStatus(404);
    }

    public function test_platform_owner_can_manage_system_templates_via_the_platform_mount(): void
    {
        $owner = User::factory()->create(['is_platform_owner' => true]);
        $headers = $this->authHeader($owner);

        $create = $this->withHeaders($headers)->postJson('/api/v1/platform/boq-templates', [
            'code' => 'SYS-1', 'name' => 'System Template', 'template_type' => BoqTemplate::TYPE_CUSTOM,
        ]);
        $create->assertStatus(201);
        $this->assertTrue($create->json('data.is_system'));
        $this->assertNull($create->json('data.organization_id'));
    }

    /**
     * Regression test for a real bug found while building the admin UI: every action-specific
     * Gate::authorize(Permissions::MANAGE_BOQ) call in BoqTemplateAdminController/
     * BoqCatalogController used to 403 for a genuine platform owner, because
     * User::can(Permissions::MANAGE_BOQ) always evaluates false without a tenant context (which
     * `platform.owner` middleware never sets up) — only the FormRequest-gated actions (store,
     * update) had the `|| is_platform_owner` fallback. This exercises every bare-Gate::authorize
     * action on the platform mount: duplicate, storeVersion, publish, usage, activate/deactivate,
     * destroyItem, destroy.
     */
    public function test_platform_owner_can_perform_every_action_specific_gated_mutation(): void
    {
        $owner = User::factory()->create(['is_platform_owner' => true]);
        $headers = $this->authHeader($owner);

        $template = BoqTemplate::factory()->system()->create();
        $catalogItem = BoqCatalogItem::factory()->system()->create();

        $version = $this->withHeaders($headers)
            ->postJson("/api/v1/platform/boq-templates/{$template->id}/versions")
            ->assertStatus(201);

        $item = $this->withHeaders($headers)
            ->postJson("/api/v1/platform/boq-templates/{$template->id}/versions/{$version->json('data.id')}/items", [
                'catalog_item_id' => $catalogItem->id,
            ])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->getJson("/api/v1/platform/boq-templates/{$template->id}/usage")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson("/api/v1/platform/boq-templates/{$template->id}/duplicate")
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson("/api/v1/platform/boq-templates/{$template->id}/deactivate")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson("/api/v1/platform/boq-templates/{$template->id}/activate")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/platform/boq-templates/items/{$item->json('data.id')}")
            ->assertStatus(204);

        $this->withHeaders($headers)
            ->postJson("/api/v1/platform/boq-templates/{$template->id}/versions/{$version->json('data.id')}/publish")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/platform/boq-catalog/items/{$catalogItem->id}")
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/platform/boq-templates/{$template->id}")
            ->assertStatus(204);
    }

    private function projectIn(Organization $organization): \App\Models\Project
    {
        $client = \App\Models\Client::factory()->create(['organization_id' => $organization->id]);

        return \App\Models\Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }
}
