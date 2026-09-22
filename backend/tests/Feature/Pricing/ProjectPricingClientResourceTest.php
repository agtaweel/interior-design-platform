<?php

namespace Tests\Feature\Pricing;

use App\Http\Resources\ProjectPricingClientResource;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md's Sprint 3 "Client-facing exposure" requirement: the client-facing pricing
 * view must return ONLY grand_total + priced_at — never direct_cost_total, client_subtotal, the
 * rule list, or markup/fee/discount breakdowns (the exact internal cost/margin data the locked
 * pricing-visibility product decision says must never leak to a client).
 *
 * As of this sprint, ProjectPricingClientResource is NOT wired to any HTTP route yet (grepped
 * app/ and routes/ — the only reference is a docblock mention in PricingRuleResource; it exists
 * purely as the seam Sprint 4's client portal will plug into). So this is a unit-style test of
 * the resource class itself, fully populated with every internal field it must never leak,
 * rather than an HTTP-level test of a route that doesn't exist.
 */
class ProjectPricingClientResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_serializes_only_grand_total_and_priced_at_even_when_the_project_is_fully_priced(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
        ]);

        $pricedAt = now();
        $project->forceFill([
            'direct_cost_total' => '1000.00',
            'client_subtotal' => '2000.00',
            'markup_total' => '150.00',
            'fees_total' => '240.00',
            'discount_total' => '50.00',
            'grand_total' => '2340.00',
            'priced_at' => $pricedAt,
        ])->save();

        $resource = (new ProjectPricingClientResource($project))->toArray(Request::create('/'));

        $this->assertSame(['grand_total', 'priced_at'], array_keys($resource));
        $this->assertSame('2340.00', $resource['grand_total']);
        $this->assertNotNull($resource['priced_at']);

        // Explicitly confirm none of the internal cost/rule/margin fields ever leak through,
        // even though the project row (and thus $this->attribute access on the resource) has
        // them populated.
        foreach (['direct_cost_total', 'client_subtotal', 'markup_total', 'fees_total', 'discount_total', 'rules'] as $internalField) {
            $this->assertArrayNotHasKey($internalField, $resource, "Client-facing resource leaked internal field: {$internalField}");
        }
    }

    public function test_grand_total_defaults_to_zero_for_a_never_priced_project(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
        ]);

        $resource = (new ProjectPricingClientResource($project))->toArray(Request::create('/'));

        $this->assertSame(0, $resource['grand_total']);
        $this->assertNull($resource['priced_at']);
    }
}
