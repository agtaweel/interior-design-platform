<?php

namespace App\Services\Costs;

use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\PurchaseOrder;
use App\Services\Boq\BoqMoney;

/**
 * BRD §8 "Project Profitability Engine": distinguishes quoted cost, committed cost, and actual
 * cost (§9's own explicit instruction: "Reports must distinguish quoted cost, committed cost
 * and actual cost"), and computes gross profit / margin % from real recorded data — replacing
 * the Sprint 6-era placeholders (actual_cost=0, gross_profit=null) that existed before
 * Procurement/Expenses were built.
 *
 * Shared by ProjectFinancialsController (per-project financials card) and ReportService
 * (org-wide Reports page + CSV export) so both surfaces compute these numbers identically —
 * same "one source of truth" reasoning ProjectFinancialsCalculator already established for
 * collected/outstanding.
 *
 * All money math via bcmath (BoqMoney::SCALE), never float/SQL SUM(), per this codebase's
 * standing money rule.
 *
 * Definitions (BRD §8, applied to this codebase's actual schema):
 *   - quoted_cost: sum of every PO line's quoted_unit_price * quantity, across every
 *     non-cancelled purchase order (what the project is on track to spend if every open PO is
 *     fulfilled as quoted).
 *   - committed_cost: same sum, but restricted to POs that have actually been sent to a
 *     supplier (sent/partially_received/received) — draft POs aren't a real commitment yet.
 *   - actual_cost: supplier purchases actually received (sum of received_quantity *
 *     actual_unit_price across every PO line that HAS been received) + every recorded
 *     ProjectExpense (BRD "Actual cost: supplier purchases + labor/contractor cost + project
 *     expenses" — labor/contractor spend is captured as an Expense with that category, this
 *     codebase has no separate labor-cost table).
 *   - gross_profit / margin_percent: null (not 0) when the project has no contract yet — same
 *     "can't compute against an unrecognized revenue basis" judgment call this class already
 *     established for `outstanding`. Revenue basis is contract_value, which already includes
 *     every applied change order's price_delta (ChangeOrderApplyService is the only code path
 *     that mutates contract_value after creation) — matching BRD §8 "Revenue: contract value +
 *     approved change orders" without double-counting.
 *
 * BRD v3 judgment call: deliberately NOT rewired to FinancialLedgerService — same "would
 * silently read back as zero for any PurchaseOrderItem/ProjectExpense created outside the
 * specific wired service methods (most directly, this app's own factory-based test fixtures)"
 * reasoning as ProjectFinancialsCalculator's docblock. The ledger is the source of truth for
 * every NEW v3 surface instead; this pre-existing internal Reports/Financials computation is
 * left untouched.
 */
final class ProjectCostCalculator
{
    /**
     * Money fields (quoted_cost/committed_cost/actual_cost/gross_profit) follow
     * BoqMoney::zeroAsInt()'s convention: a genuinely zero amount is the bare int `0`, matching
     * ProjectFinancialsCalculator's identical collected/outstanding convention (several tests
     * pin this exact contract) — anything non-zero is a full-precision bcmath decimal string.
     * margin_percent is a percentage, not money, so it is never coerced this way.
     *
     * @return array{quoted_cost: string|int, committed_cost: string|int, actual_cost: string|int, gross_profit: string|int|null, margin_percent: string|null}
     */
    public function calculate(Project $project, ?Contract $contract): array
    {
        $orders = PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', 'cancelled')
            ->with('items')
            ->get();

        $quotedCost = BoqMoney::zero();
        $committedCost = BoqMoney::zero();
        $actualCostFromPos = BoqMoney::zero();

        foreach ($orders as $order) {
            $isCommitted = in_array($order->status, ['sent', 'partially_received', 'received'], true);

            foreach ($order->items as $item) {
                $quotedCost = bcadd($quotedCost, $item->quotedTotal(), BoqMoney::SCALE);

                if ($isCommitted) {
                    $committedCost = bcadd($committedCost, $item->quotedTotal(), BoqMoney::SCALE);
                }

                if ($item->actualTotal() !== null) {
                    $actualCostFromPos = bcadd($actualCostFromPos, $item->actualTotal(), BoqMoney::SCALE);
                }
            }
        }

        $expensesTotal = BoqMoney::sumAccessor(
            ProjectExpense::query()->where('project_id', $project->id)->get(),
            'amount',
        );

        $actualCost = bcadd($actualCostFromPos, $expensesTotal, BoqMoney::SCALE);

        [$grossProfit, $marginPercent] = $this->profitAndMargin($contract, $actualCost);

        return [
            'quoted_cost' => BoqMoney::zeroAsInt($quotedCost),
            'committed_cost' => BoqMoney::zeroAsInt($committedCost),
            'actual_cost' => BoqMoney::zeroAsInt($actualCost),
            'gross_profit' => BoqMoney::zeroAsInt($grossProfit),
            'margin_percent' => $marginPercent,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function profitAndMargin(?Contract $contract, string $actualCost): array
    {
        if (! $contract) {
            return [null, null];
        }

        $revenue = (string) $contract->contract_value;
        $grossProfit = bcsub($revenue, $actualCost, BoqMoney::SCALE);

        // Zero-revenue safeguard (BRD §8): a contract_value of exactly 0 would divide-by-zero —
        // margin_percent is null in that case rather than an error or a misleading number.
        if (bccomp($revenue, BoqMoney::zero(), BoqMoney::SCALE) === 0) {
            return [$grossProfit, null];
        }

        // Percentage needs more precision than SCALE=2 money math to stay meaningful (e.g. a
        // small margin on a large contract can round to 0.00% at 2dp) — compute at 4dp then
        // present at 2dp, same "compute wide, present narrow" pattern PricingRule calculations
        // already use for percentage-based rules.
        $marginPercent = bcmul(bcdiv($grossProfit, $revenue, 4), '100', 2);

        return [$grossProfit, $marginPercent];
    }
}
