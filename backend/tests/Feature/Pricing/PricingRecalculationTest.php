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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies PROJECT_CONTEXT.md Sprint 3's "Recalculation algorithm" exactly
 * (App\Services\Pricing\PricingCalculator + PricingController::recalculate()):
 *   1. direct_cost_total = sum of non-archived boq_items.direct_cost
 *   2. client_subtotal   = sum of non-archived boq_items.client_total (fixed anchor)
 *   3. running_subtotal starts at client_subtotal
 *   4. for each active rule in sort_order: resolve base per base_selector, compute amount,
 *      apply to running_subtotal (discount subtracts, everything else adds), bucket into
 *      markup_total/fees_total/discount_total by type
 *   5. grand_total = final running_subtotal
 *   6. cache columns + priced_at persist to `projects`
 *
 * Every BOQ setup below uses round numbers (qty=10, unit costs in whole EGP) so expected totals
 * can be hand-verified from the test itself without relying on the calculator being correct.
 */
class PricingRecalculationTest extends TestCase
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
     * Seeds a single BOQ item with hand-verifiable totals:
     *   direct_cost   = (100 + 0 + 0) * 10 = 1000.00
     *   client_total  = 200 * 10           = 2000.00
     */
    private function seedKnownBoq(Project $project): void
    {
        $category = BoqCategory::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'quantity' => 10,
            'material_unit_cost' => 100,
            'labor_unit_cost' => 0,
            'other_unit_cost' => 0,
            'client_unit_price' => 200,
        ]);
    }

    private function recalculate(User $user, Project $project)
    {
        return $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/pricing/recalculate");
    }

    /** Reads the raw DB row directly, bypassing the Eloquent model/casts entirely. */
    private function rawProjectRow(Project $project): object
    {
        return DB::table('projects')->where('id', $project->id)->first();
    }

    /**
     * Normalizes a raw decimal column value read via rawProjectRow() to a fixed 2-decimal money
     * string for comparison. Needed because the test suite runs against SQLite (phpunit.xml
     * forces DB_CONNECTION=sqlite :memory: for speed/isolation from the dev Postgres database —
     * see that file's docblock) and SQLite's PDO driver returns "numeric affinity" decimal
     * columns as native int/float (e.g. 1000 or 200.0), not the fixed-scale strings Postgres'
     * driver would return (e.g. "1000.00"). This is purely a driver-formatting difference, not
     * something under the app's control — Eloquent's `decimal:2` cast is what normally papers
     * over it, but these tests deliberately read around that cast to catch mass-assignment bugs
     * (see test docblocks below), so they must normalize formatting themselves instead. A value
     * that was never written (null, e.g. the mass-assignment-silently-failed bug class this is
     * guarding against) is passed through unchanged so assertNull()/assertSame(null, ...)
     * checks elsewhere are unaffected.
     */
    private function money(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    // --- base_selector semantics ---

    public function test_boq_direct_cost_markup_computes_against_direct_cost_not_client_subtotal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // direct_cost_total=1000.00, client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id,
            'type' => 'markup',
            'method' => 'percentage',
            'value' => 10,
            'base_selector' => 'boq_direct_cost',
            'sort_order' => 1,
        ]);

        $response = $this->recalculate($user, $project)->assertStatus(200);

        // 10% of direct_cost_total (1000.00) = 100.00, NOT 10% of client_subtotal (which
        // would be 200.00) — this is the whole point of the base_selector distinction.
        $response->assertJsonPath('data.rules.0.base_amount_used', '1000.00')
            ->assertJsonPath('data.rules.0.computed_amount', '100.00')
            ->assertJsonPath('data.markup_total', '100.00')
            ->assertJsonPath('data.grand_total', '2100.00'); // 2000.00 + 100.00
    }

    public function test_boq_client_subtotal_is_a_fixed_anchor_not_a_second_running_total(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // client_subtotal=2000.00

        // Two rules, BOTH anchored to boq_client_subtotal, both 10%. If implemented correctly,
        // both compute 10% of the SAME 2000.00 base (200.00 each) — not the second computing
        // 10% of the first rule's output (which would wrongly yield 220.00).
        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Rule 1', 'type' => 'fee',
            'method' => 'percentage', 'value' => 10, 'base_selector' => 'boq_client_subtotal',
            'sort_order' => 1,
        ]);
        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Rule 2', 'type' => 'fee',
            'method' => 'percentage', 'value' => 10, 'base_selector' => 'boq_client_subtotal',
            'sort_order' => 2,
        ]);

        $response = $this->recalculate($user, $project)->assertStatus(200);

        $rules = $response->json('data.rules');
        $this->assertSame('2000.00', $rules[0]['base_amount_used']);
        $this->assertSame('200.00', $rules[0]['computed_amount']);
        // The critical assertion: rule 2's base is STILL 2000.00, not 2200.00 (the running
        // subtotal after rule 1 applied).
        $this->assertSame('2000.00', $rules[1]['base_amount_used']);
        $this->assertSame('200.00', $rules[1]['computed_amount']);

        $response->assertJsonPath('data.fees_total', '400.00')
            ->assertJsonPath('data.grand_total', '2400.00'); // 2000.00 + 200.00 + 200.00
    }

    public function test_running_subtotal_rule_uses_the_value_after_previously_applied_rules(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Flat Fee', 'type' => 'fee',
            'method' => 'fixed_amount', 'value' => 100, 'base_selector' => 'running_subtotal',
            'sort_order' => 1,
        ]);
        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Percent Fee', 'type' => 'fee',
            'method' => 'percentage', 'value' => 10, 'base_selector' => 'running_subtotal',
            'sort_order' => 2,
        ]);

        $response = $this->recalculate($user, $project)->assertStatus(200);
        $rules = $response->json('data.rules');

        // running_subtotal starts at 2000.00, rule 1 (+100 fixed) brings it to 2100.00, then
        // rule 2 (10% of running_subtotal) reads that 2100.00 as ITS base, not the original
        // 2000.00 client_subtotal.
        $this->assertSame('2000.00', $rules[0]['base_amount_used']);
        $this->assertSame('2100.00', $rules[0]['running_subtotal_after']);
        $this->assertSame('2100.00', $rules[1]['base_amount_used']);
        $this->assertSame('210.00', $rules[1]['computed_amount']);
        $response->assertJsonPath('data.grand_total', '2310.00'); // 2100.00 + 210.00
    }

    public function test_reordering_running_subtotal_rules_changes_the_final_result(): void
    {
        $organization = Organization::factory()->create();

        // Order A: fixed fee first (sort_order 1), then percentage fee (sort_order 2).
        $projectA = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($projectA);
        PricingRule::factory()->create([
            'project_id' => $projectA->id, 'type' => 'fee', 'method' => 'fixed_amount',
            'value' => 100, 'base_selector' => 'running_subtotal', 'sort_order' => 1,
        ]);
        PricingRule::factory()->create([
            'project_id' => $projectA->id, 'type' => 'fee', 'method' => 'percentage',
            'value' => 10, 'base_selector' => 'running_subtotal', 'sort_order' => 2,
        ]);
        $resultA = $this->recalculate($user, $projectA)->assertStatus(200);
        // running=2000 -> +100 fixed = 2100 -> +10% of 2100 (210) = 2310
        $resultA->assertJsonPath('data.grand_total', '2310.00');

        // Order B: same two rules, sort_order swapped — percentage first, then fixed.
        $projectB = $this->projectIn($organization);
        $this->seedKnownBoq($projectB);
        PricingRule::factory()->create([
            'project_id' => $projectB->id, 'type' => 'fee', 'method' => 'percentage',
            'value' => 10, 'base_selector' => 'running_subtotal', 'sort_order' => 1,
        ]);
        PricingRule::factory()->create([
            'project_id' => $projectB->id, 'type' => 'fee', 'method' => 'fixed_amount',
            'value' => 100, 'base_selector' => 'running_subtotal', 'sort_order' => 2,
        ]);
        $resultB = $this->recalculate($user, $projectB)->assertStatus(200);
        // running=2000 -> +10% of 2000 (200) = 2200 -> +100 fixed = 2300
        $resultB->assertJsonPath('data.grand_total', '2300.00');

        // Same rules, same values, different order -> different grand_total. Proves
        // sort_order genuinely drives the running_subtotal walk.
        $this->assertNotSame($resultA->json('data.grand_total'), $resultB->json('data.grand_total'));
    }

    // --- type/method semantics ---

    public function test_discount_type_subtracts_from_the_running_subtotal(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'discount', 'method' => 'fixed_amount',
            'value' => 300, 'base_selector' => 'running_subtotal', 'sort_order' => 1,
        ]);

        $response = $this->recalculate($user, $project)->assertStatus(200);

        // computed_amount is reported as an unsigned magnitude (300.00), per the calculator's
        // documented sign convention, but grand_total must reflect the subtraction.
        $response->assertJsonPath('data.rules.0.computed_amount', '300.00')
            ->assertJsonPath('data.discount_total', '300.00')
            ->assertJsonPath('data.markup_total', '0.00')
            ->assertJsonPath('data.fees_total', '0.00')
            ->assertJsonPath('data.grand_total', '1700.00'); // 2000.00 - 300.00
    }

    public function test_fixed_amount_method_uses_the_raw_value_regardless_of_base(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // direct_cost_total=1000.00, client_subtotal=2000.00 — different bases

        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Fixed vs direct cost', 'type' => 'fee',
            'method' => 'fixed_amount', 'value' => 500, 'base_selector' => 'boq_direct_cost',
            'sort_order' => 1,
        ]);
        PricingRule::factory()->create([
            'project_id' => $project->id, 'name' => 'Fixed vs client subtotal', 'type' => 'fee',
            'method' => 'fixed_amount', 'value' => 500, 'base_selector' => 'boq_client_subtotal',
            'sort_order' => 2,
        ]);

        $response = $this->recalculate($user, $project)->assertStatus(200);
        $rules = $response->json('data.rules');

        // Both rules compute the SAME 500.00 amount even though their declared bases differ
        // (1000.00 vs 2000.00) — fixed_amount ignores the base entirely.
        $this->assertSame('500.00', $rules[0]['computed_amount']);
        $this->assertSame('500.00', $rules[1]['computed_amount']);
        $response->assertJsonPath('data.fees_total', '1000.00');
    }

    // --- active flag ---

    public function test_inactive_rules_are_excluded_from_calculation_entirely(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'markup', 'method' => 'fixed_amount',
            'value' => 99999, 'base_selector' => 'boq_client_subtotal', 'active' => false,
        ]);

        $response = $this->recalculate($user, $project)->assertStatus(200);

        $response->assertJsonPath('data.rules', [])
            ->assertJsonPath('data.markup_total', '0.00')
            ->assertJsonPath('data.grand_total', '2000.00'); // unaffected by the inactive rule
    }

    // --- bucketing by type ---

    public function test_markup_fees_and_discount_totals_bucket_correctly_by_rule_type(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project); // direct_cost_total=1000.00, client_subtotal=2000.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'markup', 'method' => 'percentage',
            'value' => 15, 'base_selector' => 'boq_direct_cost', 'sort_order' => 1,
        ]); // 15% of 1000 = 150.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'fee', 'method' => 'percentage',
            'value' => 12, 'base_selector' => 'boq_client_subtotal', 'sort_order' => 2,
        ]); // 12% of 2000 = 240.00

        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'discount', 'method' => 'fixed_amount',
            'value' => 50, 'base_selector' => 'running_subtotal', 'sort_order' => 3,
        ]); // flat 50.00 off

        $response = $this->recalculate($user, $project)->assertStatus(200);

        $response->assertJsonPath('data.markup_total', '150.00')
            ->assertJsonPath('data.fees_total', '240.00')
            ->assertJsonPath('data.discount_total', '50.00')
            // running: 2000 + 150 + 240 - 50 = 2340.00
            ->assertJsonPath('data.grand_total', '2340.00');
    }

    // --- persistence / cache columns ---

    public function test_recalculate_persists_cache_columns_directly_to_the_projects_table(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project);

        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'fee', 'method' => 'percentage',
            'value' => 10, 'base_selector' => 'boq_client_subtotal',
        ]);

        // Sanity: never priced before recalculation.
        $before = $this->rawProjectRow($project);
        $this->assertNull($before->direct_cost_total);
        $this->assertNull($before->grand_total);
        $this->assertNull($before->priced_at);

        $this->recalculate($user, $project)->assertStatus(200);

        // Read the RAW DB row directly (DB::table, not the Eloquent model) so a mass-assignment
        // bug where the HTTP response looks right but the actual write silently failed (e.g. if
        // forceFill() were ever swapped back for a guarded update()/fill() call) cannot hide
        // behind a passing response-only assertion.
        $after = $this->rawProjectRow($project);
        $this->assertSame('1000.00', $this->money($after->direct_cost_total));
        $this->assertSame('2000.00', $this->money($after->client_subtotal));
        $this->assertSame('0.00', $this->money($after->markup_total));
        $this->assertSame('200.00', $this->money($after->fees_total));
        $this->assertSame('0.00', $this->money($after->discount_total));
        $this->assertSame('2200.00', $this->money($after->grand_total));
        $this->assertNotNull($after->priced_at);
    }

    public function test_priced_at_is_set_on_first_recalculate_and_updated_on_subsequent_ones(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project);

        $this->assertNull($this->rawProjectRow($project)->priced_at);

        $this->travelTo(now()->subHour());
        $this->recalculate($user, $project)->assertStatus(200);
        $firstPricedAt = $this->rawProjectRow($project)->priced_at;
        $this->assertNotNull($firstPricedAt);

        $this->travelBack();
        $this->recalculate($user, $project)->assertStatus(200);
        $secondPricedAt = $this->rawProjectRow($project)->priced_at;

        $this->assertNotNull($secondPricedAt);
        $this->assertNotSame($firstPricedAt, $secondPricedAt);
    }

    public function test_recalculate_runs_inside_a_transaction_and_all_cache_columns_move_together(): void
    {
        // Not a literal rollback-on-failure test (there's no natural failure point to force
        // inside PricingCalculator::calculate() without mocking internals) — instead this pins
        // the observable contract of "transactional": after a successful recalculate, EVERY
        // cache column reflects the SAME calculation run (no partial/torn write), which is what
        // actually matters for a caller reading the row concurrently.
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project);
        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'markup', 'method' => 'percentage',
            'value' => 20, 'base_selector' => 'boq_direct_cost',
        ]);

        $this->recalculate($user, $project)->assertStatus(200);

        $row = $this->rawProjectRow($project);
        // 20% of 1000.00 = 200.00, grand_total = 2000.00 + 200.00 = 2200.00 — every column
        // consistent with a single calculation pass.
        $this->assertSame('200.00', $this->money($row->markup_total));
        $this->assertSame('2200.00', $this->money($row->grand_total));
    }

    // --- ProjectResource wiring (Sprint 3 explicitly requires financials.value to populate) ---

    public function test_project_resource_financials_value_reflects_grand_total_after_recalculate(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $this->seedKnownBoq($project);
        PricingRule::factory()->create([
            'project_id' => $project->id, 'type' => 'fee', 'method' => 'fixed_amount',
            'value' => 500, 'base_selector' => 'boq_client_subtotal',
        ]);

        $this->recalculate($user, $project)->assertStatus(200);

        $show = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertStatus(200);

        $this->assertSame('2500.00', $show->json('data.financials.value'));
    }
}
