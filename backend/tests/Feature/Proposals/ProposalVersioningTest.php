<?php

namespace Tests\Feature\Proposals;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PricingRule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers PROJECT_CONTEXT.md Sprint 4 "API" -> POST /projects/{id}/proposals: snapshotting
 * non-archived boq_items into proposal_items, snapshotting the pricing breakdown via
 * PricingCalculator, per-project version_no sequencing, and the documented "multiple concurrent
 * drafts are allowed" product decision.
 */
class ProposalVersioningTest extends TestCase
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

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [Permissions::MANAGE_BOQ => true]);
    }

    /**
     * quantity=10, client_unit_price=200 -> client_total=2000.00; material=100 -> direct_cost=1000.00.
     * Matches PricingRecalculationTest's hand-verifiable seed so totals can be checked by hand.
     */
    private function seedKnownBoqItem(Project $project, array $overrides = []): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(array_merge([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'quantity' => 10,
            'unit' => 'm2',
            'material_unit_cost' => 100,
            'labor_unit_cost' => 0,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ], $overrides));
    }

    private function createDraft(array $headers, Project $project, array $body = [])
    {
        return $this->withHeaders($headers)->postJson("/api/v1/projects/{$project->id}/proposals", $body);
    }

    public function test_creating_a_draft_snapshots_non_archived_boq_items_with_matching_figures(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $item = $this->seedKnownBoqItem($project);
        // A second, differently-priced item so we're not just matching on one lucky value.
        $item2 = $this->seedKnownBoqItem($project, ['quantity' => 3, 'client_unit_price' => 150, 'unit' => 'pcs']);

        $response = $this->createDraft($this->authHeader($user), $project)->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);

        $byDescription = collect($items)->keyBy('description');
        $row1 = $byDescription->get($item->description ?: $item->name);
        $row2 = $byDescription->get($item2->description ?: $item2->name);

        $this->assertNotNull($row1);
        $this->assertSame('10.00', $row1['quantity']);
        $this->assertSame('m2', $row1['unit']);
        $this->assertSame('200.00', $row1['unit_price']);
        $this->assertSame('2000.00', $row1['line_total']);
        $this->assertSame($item->id, $row1['source_boq_item_id']);

        $this->assertNotNull($row2);
        $this->assertSame('3.00', $row2['quantity']);
        $this->assertSame('150.00', $row2['unit_price']);
        $this->assertSame('450.00', $row2['line_total']);

        // Persisted, not just in the response.
        $this->assertDatabaseCount('proposal_items', 2);
    }

    public function test_archived_boq_items_are_excluded_from_the_snapshot(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $active = $this->seedKnownBoqItem($project);
        $archived = $this->seedKnownBoqItem($project, ['archived_at' => now()]);

        $response = $this->createDraft($this->authHeader($user), $project)->assertStatus(201);

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($active->id, $items[0]['source_boq_item_id']);
        $this->assertNotContains($archived->id, collect($items)->pluck('source_boq_item_id')->all());
    }

    public function test_falls_back_to_boq_item_name_when_description_is_null(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $item = $this->seedKnownBoqItem($project, ['description' => null, 'name' => 'Named Item Only']);

        $response = $this->createDraft($this->authHeader($user), $project)->assertStatus(201);

        $this->assertSame('Named Item Only', $response->json('data.items.0.description'));
    }

    public function test_draft_totals_match_pricing_calculator_output_for_a_project_with_known_rules(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $this->seedKnownBoqItem($project); // direct_cost=1000.00, client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id,
            'type' => 'fee',
            'method' => 'percentage',
            'value' => 10,
            'base_selector' => 'boq_client_subtotal',
            'sort_order' => 1,
        ]); // 10% of 2000.00 = 200.00

        PricingRule::factory()->create([
            'project_id' => $project->id,
            'type' => 'discount',
            'method' => 'fixed_amount',
            'value' => 50,
            'base_selector' => 'running_subtotal',
            'sort_order' => 2,
        ]); // flat 50.00 off

        $response = $this->createDraft($this->authHeader($user), $project)->assertStatus(201);

        // subtotal maps to PricingCalculator's client_subtotal (the fixed anchor), not
        // direct_cost_total — direct cost must never appear anywhere on this client-facing model.
        $response->assertJsonPath('data.subtotal', '2000.00')
            ->assertJsonPath('data.markup_total', '0.00')
            ->assertJsonPath('data.fees_total', '200.00')
            ->assertJsonPath('data.discount_total', '50.00')
            // 2000.00 + 200.00 - 50.00 = 2150.00
            ->assertJsonPath('data.grand_total', '2150.00');

        // Confirm this is genuinely independent of the project's own cache columns (which were
        // never populated by a POST /projects/{id}/pricing/recalculate call in this test) —
        // proving ProposalVersionService calls PricingCalculator directly rather than reading a
        // stale/absent cache.
        $this->assertNull($project->fresh()->grand_total);
    }

    public function test_version_no_increments_per_project_starting_at_one_and_is_independent_across_projects(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);
        $this->seedKnownBoqItem($projectA);
        $this->seedKnownBoqItem($projectB);

        $first = $this->createDraft($headers, $projectA)->assertStatus(201);
        $this->assertSame(1, $first->json('data.version_no'));

        $second = $this->createDraft($headers, $projectA)->assertStatus(201);
        $this->assertSame(2, $second->json('data.version_no'));

        $third = $this->createDraft($headers, $projectA)->assertStatus(201);
        $this->assertSame(3, $third->json('data.version_no'));

        // A second project's own sequence starts at 1 independently of project A's count.
        $otherProjectFirst = $this->createDraft($headers, $projectB)->assertStatus(201);
        $this->assertSame(1, $otherProjectFirst->json('data.version_no'));
    }

    public function test_multiple_concurrent_draft_versions_are_allowed_for_the_same_project(): void
    {
        // Per PROJECT_CONTEXT.md's documented product decision (backend-api-engineer's
        // judgment call, ProposalVersionService's docblock): nothing prevents two drafts
        // existing simultaneously for one project — there is no "only one active draft" guard
        // anywhere in createDraft(). This test proves that's real and intended, not an
        // oversight: creating a second draft while the first is still status=draft succeeds
        // (not a 409), and both rows persist independently.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $headers = $this->authHeader($user);
        $this->seedKnownBoqItem($project);

        $draftOne = $this->createDraft($headers, $project)->assertStatus(201);
        $draftOne->assertJsonPath('data.status', 'draft');

        // First draft is still 'draft' — nothing transitioned it away.
        $draftTwo = $this->createDraft($headers, $project)->assertStatus(201);
        $draftTwo->assertJsonPath('data.status', 'draft');

        $this->assertNotSame($draftOne->json('data.id'), $draftTwo->json('data.id'));
        $this->assertSame(
            2,
            ProposalVersion::query()->where('project_id', $project->id)->where('status', 'draft')->count()
        );
    }

    public function test_content_json_is_accepted_and_stored_verbatim_on_create(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoqItem($project);

        $content = ['cover_note' => 'Thank you for choosing us', 'scope' => 'Full apartment finishing'];

        $response = $this->createDraft($this->authHeader($user), $project, ['content_json' => $content])
            ->assertStatus(201);

        $this->assertSame($content, $response->json('data.content_json'));
    }

    public function test_creating_a_draft_for_a_nonexistent_project_returns_404(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/projects/999999/proposals')
            ->assertStatus(404);
    }
}
