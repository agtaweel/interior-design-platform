<?php

namespace Tests\Feature\Pricing;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PricingRule;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The projects pricing cache (grand_total & co. — what financials.value, the dashboard and
 * reports read) now follows every BOQ item / pricing rule change automatically
 * (ProjectPricingObserver -> ProjectPricingCache), instead of waiting for someone to press
 * "Recalculate". See ProjectPricingCache's docblock.
 */
class AutomaticPricingRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function userWithManageBoq(Organization $organization): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => [Permissions::MANAGE_BOQ => true]]);
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

    /** 10 x 200 client price = 2000.00 client subtotal, 1000.00 direct cost. */
    private function item(Project $project, array $overrides = []): BoqItem
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        return BoqItem::factory()->create(array_merge([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'quantity' => 10,
            'material_unit_cost' => 100,
            'labor_unit_cost' => 0,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ], $overrides));
    }

    private function grandTotal(Project $project): ?string
    {
        $value = DB::table('projects')->where('id', $project->id)->value('grand_total');

        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    public function test_editing_an_item_through_the_api_updates_the_project_total(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $item = $this->item($project);
        $this->assertSame('2000.00', $this->grandTotal($project));

        $this->withHeaders($this->authHeader($this->userWithManageBoq($organization)))
            ->patchJson("/api/v1/boq/items/{$item->id}", ['quantity' => 15])
            ->assertStatus(200);

        $this->assertSame('3000.00', $this->grandTotal($project));
    }

    public function test_removing_an_item_through_the_api_updates_the_project_total(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->item($project);
        $second = $this->item($project, ['quantity' => 5]); // +1000.00
        $this->assertSame('3000.00', $this->grandTotal($project));

        $this->withHeaders($this->authHeader($this->userWithManageBoq($organization)))
            ->deleteJson("/api/v1/boq/items/{$second->id}")
            ->assertSuccessful();

        $this->assertSame('2000.00', $this->grandTotal($project));
    }

    public function test_adding_and_removing_a_pricing_rule_updates_the_project_total(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $this->item($project);
        $headers = $this->authHeader($this->userWithManageBoq($organization));

        $ruleId = $this->withHeaders($headers)
            ->postJson("/api/v1/projects/{$project->id}/pricing/rules", [
                'name' => 'Markup', 'type' => 'markup', 'method' => 'percentage',
                'value' => 20, 'base_selector' => 'boq_client_subtotal',
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame('2400.00', $this->grandTotal($project));

        $this->withHeaders($headers)->deleteJson("/api/v1/pricing/rules/{$ruleId}")->assertSuccessful();

        $this->assertSame('2000.00', $this->grandTotal($project));
    }

    public function test_many_changes_in_one_transaction_recalculate_once_after_commit(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        // PricingCalculator reads the project's items once per run — count those reads.
        $calculatorReads = 0;
        DB::listen(function (QueryExecuted $query) use (&$calculatorReads) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"boq_items"')) {
                $calculatorReads++;
            }
        });

        DB::transaction(function () use ($project, $category) {
            foreach ([1, 2, 3] as $quantity) {
                BoqItem::factory()->create([
                    'project_id' => $project->id, 'category_id' => $category->id,
                    'quantity' => $quantity, 'client_unit_price' => 100,
                ]);
            }

            // Still inside the transaction: nothing recalculated yet.
            $this->assertNull($this->grandTotal($project));
        });

        $this->assertSame(1, $calculatorReads);
        $this->assertSame('600.00', $this->grandTotal($project));
    }

    public function test_a_rolled_back_change_writes_nothing_and_does_not_block_later_refreshes(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);

        try {
            DB::transaction(function () use ($project, $category) {
                BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id, 'quantity' => 1, 'client_unit_price' => 100]);

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull($this->grandTotal($project));

        // The project must not be stuck as "refresh already pending".
        BoqItem::factory()->create(['project_id' => $project->id, 'category_id' => $category->id, 'quantity' => 2, 'client_unit_price' => 100]);

        $this->assertSame('200.00', $this->grandTotal($project));
    }

    public function test_a_cosmetic_edit_does_not_restamp_priced_at(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $item = $this->item($project);
        $pricedAt = DB::table('projects')->where('id', $project->id)->value('priced_at');
        $this->assertNotNull($pricedAt);

        $this->travel(5)->minutes();
        $item->update(['notes' => 'Client prefers matte finish']);

        $this->assertEquals($pricedAt, DB::table('projects')->where('id', $project->id)->value('priced_at'));
    }

    public function test_rule_edits_only_reprice_their_own_project(): void
    {
        $organization = Organization::factory()->create();
        $projectA = $this->projectIn($organization);
        $projectB = $this->projectIn($organization);
        $this->item($projectA);
        $this->item($projectB);

        PricingRule::factory()->create([
            'project_id' => $projectA->id, 'type' => 'fee', 'method' => 'fixed_amount',
            'value' => 100, 'base_selector' => 'boq_client_subtotal',
        ]);

        $this->assertSame('2100.00', $this->grandTotal($projectA));
        $this->assertSame('2000.00', $this->grandTotal($projectB));
    }
}
