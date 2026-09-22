<?php

namespace App\Services\Pricing;

use App\Models\Project;
use App\Services\Boq\BoqMoney;
use UnexpectedValueException;

/**
 * Implements the exact "Recalculation algorithm" from docs/PROJECT_CONTEXT.md's Sprint 3 scope.
 * This is a pure, side-effect-free calculation: it reads the project's non-archived boq_items
 * and active pricing_rules and returns the computed totals + a step-by-step rule breakdown, but
 * never writes anything. Callers decide what to do with the result:
 *   - PricingController::recalculate() persists it onto `projects`' cache columns (the only
 *     place this algorithm's output becomes the source of truth).
 *   - PricingController::breakdown() uses it to render a live "what would recalculating produce
 *     right now" preview for the internal Pricing Panel's "show formulas clearly" requirement,
 *     without saving anything (see that controller's docblock for the priced_at-null gating
 *     this is deliberately paired with).
 *
 * All arithmetic uses bcmath decimal strings throughout (never float), per PROJECT_CONTEXT.md's
 * money rules — mirrors BoqItem's direct_cost/client_total accessors and reuses BoqMoney for
 * zero/add so accumulation never drops into float precision.
 *
 * Sign convention: `computed_amount` (per rule) and the accumulated `markup_total`/
 * `fees_total`/`discount_total` are all UNSIGNED magnitudes — e.g. a 10% discount rule against
 * a 1000.00 base reports computed_amount = "100.00", not "-100.00", and contributes +100.00 to
 * discount_total. The rule's `type` (markup|fee|discount) tells the caller which direction it
 * moved the total; the running subtotal itself is what actually reflects the sign (discounts
 * are subtracted from `running_subtotal_after`, everything else is added). This matches how a
 * designer reads a pricing breakdown ("Discount: 100.00 EGP off"), not raw signed ledger math.
 */
final class PricingCalculator
{
    /**
     * @return array{
     *     direct_cost_total: string,
     *     client_subtotal: string,
     *     rules: list<array{
     *         id: int, name: string, type: string, method: string, value: string,
     *         base_selector: string, base_amount_used: string, computed_amount: string,
     *         running_subtotal_after: string
     *     }>,
     *     markup_total: string,
     *     fees_total: string,
     *     discount_total: string,
     *     grand_total: string
     * }
     */
    public function calculate(Project $project): array
    {
        $items = $project->boqItems()->whereNull('archived_at')->get();

        $directCostTotal = BoqMoney::sumAccessor($items, 'direct_cost');
        $clientSubtotal = BoqMoney::sumAccessor($items, 'client_total');

        $runningSubtotal = $clientSubtotal;
        $markupTotal = BoqMoney::zero();
        $feesTotal = BoqMoney::zero();
        $discountTotal = BoqMoney::zero();
        $ruleBreakdown = [];

        $rules = $project->pricingRules()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $base = match ($rule->base_selector) {
                'boq_direct_cost' => $directCostTotal,
                'boq_client_subtotal' => $clientSubtotal,
                'running_subtotal' => $runningSubtotal,
                default => throw new UnexpectedValueException("Unknown base_selector: {$rule->base_selector}"),
            };

            $amount = $this->computeAmount($base, $rule->method, (string) $rule->value);

            $runningSubtotal = $rule->type === 'discount'
                ? bcsub($runningSubtotal, $amount, BoqMoney::SCALE)
                : bcadd($runningSubtotal, $amount, BoqMoney::SCALE);

            match ($rule->type) {
                'markup' => $markupTotal = bcadd($markupTotal, $amount, BoqMoney::SCALE),
                'fee' => $feesTotal = bcadd($feesTotal, $amount, BoqMoney::SCALE),
                'discount' => $discountTotal = bcadd($discountTotal, $amount, BoqMoney::SCALE),
                default => throw new UnexpectedValueException("Unknown rule type: {$rule->type}"),
            };

            $ruleBreakdown[] = [
                'id' => $rule->id,
                'name' => $rule->name,
                'type' => $rule->type,
                'method' => $rule->method,
                'value' => (string) $rule->value,
                'base_selector' => $rule->base_selector,
                'base_amount_used' => $base,
                'computed_amount' => $amount,
                'running_subtotal_after' => $runningSubtotal,
            ];
        }

        return [
            'direct_cost_total' => $directCostTotal,
            'client_subtotal' => $clientSubtotal,
            'rules' => $ruleBreakdown,
            'markup_total' => $markupTotal,
            'fees_total' => $feesTotal,
            'discount_total' => $discountTotal,
            'grand_total' => $runningSubtotal,
        ];
    }

    /**
     * percentage: base * value/100 (value is e.g. "15.00" meaning 15%). Multiplies at an
     * intermediate scale of 4 (2 + 2, the max scale of the two decimal:2 operands) before
     * dividing by 100 and rounding to money scale, so the divide is the only rounding point.
     * fixed_amount: the value itself, normalized to money scale (bcadd against "0" applies
     * BoqMoney::SCALE rounding/padding consistently rather than trusting the caller's string).
     */
    private function computeAmount(string $base, string $method, string $value): string
    {
        if ($method === 'percentage') {
            $product = bcmul($base, $value, 4);

            return bcdiv($product, '100', BoqMoney::SCALE);
        }

        if ($method === 'fixed_amount') {
            return bcadd($value, BoqMoney::zero(), BoqMoney::SCALE);
        }

        throw new UnexpectedValueException("Unknown method: {$method}");
    }
}
