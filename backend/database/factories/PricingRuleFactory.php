<?php

namespace Database\Factories;

use App\Models\PricingRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingRule>
 */
class PricingRuleFactory extends Factory
{
    /**
     * Default state: a generic percentage fee against the client subtotal. Use the named
     * states below (designFee/contractorMarkup/loyaltyDiscount) for the three realistic
     * examples from docs/PROJECT_CONTEXT.md's Sprint 3 scope, or override fields inline for
     * anything else a test needs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['Design Fee', 'Contractor Markup', 'Supervision Fee']),
            'type' => 'fee',
            'method' => 'percentage',
            'value' => fake()->randomFloat(2, 2, 20),
            'base_selector' => 'boq_client_subtotal',
            'sort_order' => 0,
            'active' => true,
        ];
    }

    /**
     * "Design Fee" — fee/percentage/12%/boq_client_subtotal, per PROJECT_CONTEXT.md's example.
     */
    public function designFee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Design Fee',
            'type' => 'fee',
            'method' => 'percentage',
            'value' => 12,
            'base_selector' => 'boq_client_subtotal',
        ]);
    }

    /**
     * "Contractor Markup" — markup/percentage/15%/boq_direct_cost, per PROJECT_CONTEXT.md's
     * example (a cost-plus-style markup on direct cost rather than the client subtotal).
     */
    public function contractorMarkup(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Contractor Markup',
            'type' => 'markup',
            'method' => 'percentage',
            'value' => 15,
            'base_selector' => 'boq_direct_cost',
        ]);
    }

    /**
     * "Loyalty Discount" — discount/fixed_amount/5000 EGP/running_subtotal, per
     * PROJECT_CONTEXT.md's example (a flat discount applied after previously-applied rules).
     */
    public function loyaltyDiscount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Loyalty Discount',
            'type' => 'discount',
            'method' => 'fixed_amount',
            'value' => 5000,
            'base_selector' => 'running_subtotal',
        ]);
    }

    /**
     * Convenience state for tests exercising the "inactive rules are ignored by recalculation"
     * rule from PROJECT_CONTEXT.md's Sprint 3 scope.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
