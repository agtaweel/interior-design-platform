<?php

namespace App\Services\Reports;

use App\Models\ChangeOrder;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Project;
use App\Services\Boq\BoqMoney;
use App\Services\Costs\ProjectCostCalculator;

/**
 * GET /reports/summary and GET/CSV /reports/projects (PROJECT_CONTEXT.md Sprint 8 "Reports").
 * Both gated behind Permissions::VIEW_FINANCIALS at the controller — this class assumes it's
 * only ever called once that check has passed.
 *
 * Works from BOQ-estimated direct cost (`projects.direct_cost_total`/`.grand_total`, Sprint 3's
 * pricing cache columns), contract value, recorded payments, AND — since Procurement/Expenses
 * now exist (BRD §8/§9) — real Purchase Order and Expense data via ProjectCostCalculator for
 * `actual_cost`/`gross_profit`/`committed_cost`/`quoted_cost`. `estimated_margin` stays a
 * separate, purely BOQ-derived figure (grand_total - direct_cost_total) — it is what it always
 * was (a pricing-time estimate), never confused with the real `gross_profit` figure now
 * available alongside it.
 *
 * Tenant scoping needs no explicit organization_id filter anywhere in this class: Project and
 * Payment both use BelongsToOrganization directly (their query builders are already constrained
 * to the current TenantContext by OrganizationScope — see that trait's docblock), and
 * Contract/ChangeOrder are scoped indirectly by only ever being queried through a specific
 * already-scoped Project's id (`where('project_id', $project->id)`) — the same pattern
 * PricingRuleController/ContractController use to re-resolve indirectly-scoped rows.
 *
 * bcmath throughout, per PROJECT_CONTEXT.md's money rules — every total here is a decimal
 * string accumulated via BoqMoney/bcadd/bcsub, never SQL SUM() or float arithmetic.
 */
final class ReportService
{
    public function __construct(private readonly ProjectCostCalculator $costCalculator) {}

    /**
     * @return array{
     *     total_revenue: string, total_receivables: string, estimated_margin: string,
     *     total_change_order_value: string, total_actual_cost: string, total_gross_profit: string,
     *     project_count: int, active_project_count: int
     * }
     */
    public function summary(): array
    {
        $projects = Project::query()->get();

        $totalRevenue = BoqMoney::sumAccessor(Payment::query()->get(), 'amount');

        $totalReceivables = BoqMoney::zero();
        $estimatedMargin = BoqMoney::zero();
        $totalChangeOrderValue = BoqMoney::zero();
        $totalActualCost = BoqMoney::zero();
        $totalGrossProfit = BoqMoney::zero();

        foreach ($projects as $project) {
            $contract = $this->latestContract($project);
            $costs = $this->costCalculator->calculate($project, $contract);
            $totalActualCost = bcadd($totalActualCost, $costs['actual_cost'], BoqMoney::SCALE);

            if ($costs['gross_profit'] !== null) {
                $totalGrossProfit = bcadd($totalGrossProfit, $costs['gross_profit'], BoqMoney::SCALE);
            }

            if ($contract) {
                // Sum of (contract_value - collected) across every project with a contract, per
                // PROJECT_CONTEXT.md's literal formula for this org-wide KPI — deliberately NOT
                // clamped at 0 per-project the way the per-project `outstanding` column /
                // ProjectFinancialsCalculator's receivables view is (that clamping exists so a
                // single project's receivables card never shows a confusing negative balance;
                // this is an aggregate KPI summing the raw formula the spec names).
                $collected = $this->collectedFor($project);
                $totalReceivables = bcadd(
                    $totalReceivables,
                    bcsub((string) $contract->contract_value, $collected, BoqMoney::SCALE),
                    BoqMoney::SCALE
                );
            }

            if ($project->priced_at !== null) {
                $estimatedMargin = bcadd(
                    $estimatedMargin,
                    bcsub((string) $project->grand_total, (string) $project->direct_cost_total, BoqMoney::SCALE),
                    BoqMoney::SCALE
                );
            }

            $totalChangeOrderValue = bcadd($totalChangeOrderValue, $this->appliedChangeOrderValueFor($project), BoqMoney::SCALE);
        }

        return [
            'total_revenue' => $totalRevenue,
            'total_receivables' => $totalReceivables,
            // Labeled "estimated_margin", never "profit" — per PROJECT_CONTEXT.md's explicit
            // instruction, since this is derived from BOQ pricing, not real expenses.
            'estimated_margin' => $estimatedMargin,
            'total_change_order_value' => $totalChangeOrderValue,
            // Real actual cost / gross profit (BRD §8), via ProjectCostCalculator — summed
            // only across projects that HAVE a contract for gross_profit (no recognized
            // revenue basis otherwise, same convention as that calculator's per-project null).
            'total_actual_cost' => $totalActualCost,
            'total_gross_profit' => $totalGrossProfit,
            'project_count' => $projects->count(),
            'active_project_count' => $projects->where('status', 'active')->count(),
        ];
    }

    /**
     * Per-project breakdown rows for GET /reports/projects and its CSV export — same rows,
     * different serialization (ReportController::projects()/projectsExport()).
     *
     * @return list<array{
     *     id: int, name: string, code: string, status: string, contract_value: string,
     *     collected: string, outstanding: string, estimated_margin: string,
     *     change_order_value: string, budget_variance: string, quoted_cost: string,
     *     committed_cost: string, actual_cost: string, gross_profit: string|null,
     *     margin_percent: string|null
     * }>
     */
    public function projectRows(): array
    {
        return Project::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => $this->rowFor($project))
            ->all();
    }

    /**
     * @return array{
     *     id: int, name: string, code: string, status: string, contract_value: string,
     *     collected: string, outstanding: string, estimated_margin: string,
     *     change_order_value: string, budget_variance: string, quoted_cost: string,
     *     committed_cost: string, actual_cost: string, gross_profit: string|null,
     *     margin_percent: string|null
     * }
     */
    private function rowFor(Project $project): array
    {
        $contract = $this->latestContract($project, ['proposalVersion']);
        $contractValue = $contract ? (string) $contract->contract_value : BoqMoney::zero();
        $collected = $this->collectedFor($project);
        $costs = $this->costCalculator->calculate($project, $contract);

        // Same clamp-at-zero convention as ProjectFinancialsCalculator::resolveOutstanding() —
        // this IS the per-project receivables view, so it should read identically to
        // GET /projects/{id}/financials.outstanding for the same project.
        $outstandingRaw = $contract
            ? bcsub($contractValue, $collected, BoqMoney::SCALE)
            : BoqMoney::zero();
        $outstanding = bccomp($outstandingRaw, BoqMoney::zero(), BoqMoney::SCALE) < 0
            ? BoqMoney::zero()
            : $outstandingRaw;

        $estimatedMargin = $project->priced_at !== null
            ? bcsub((string) $project->grand_total, (string) $project->direct_cost_total, BoqMoney::SCALE)
            : BoqMoney::zero();

        $changeOrderValue = $this->appliedChangeOrderValueFor($project);

        // contract_value - the contract's proposalVersion.grand_total (the ORIGINAL, frozen
        // commercial value at signing) — per construction (ChangeOrderApplyService::
        // applyToContract() is the ONLY code path that ever mutates contract_value after
        // creation, and it adds exactly price_delta), this equals change_order_value exactly.
        // No contract yet -> both are 0, trivially equal.
        $budgetVariance = ($contract && $contract->proposalVersion)
            ? bcsub($contractValue, (string) $contract->proposalVersion->grand_total, BoqMoney::SCALE)
            : BoqMoney::zero();

        return [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'status' => $project->status,
            'contract_value' => $contractValue,
            'collected' => $collected,
            'outstanding' => $outstanding,
            'estimated_margin' => $estimatedMargin,
            'change_order_value' => $changeOrderValue,
            'budget_variance' => $budgetVariance,
            'quoted_cost' => $costs['quoted_cost'],
            'committed_cost' => $costs['committed_cost'],
            'actual_cost' => $costs['actual_cost'],
            'gross_profit' => $costs['gross_profit'],
            'margin_percent' => $costs['margin_percent'],
        ];
    }

    /**
     * "The project's contract" in the singular — same "most recently signed, if more than one"
     * resolution as ProjectFinancialsCalculator::resolveOutstanding() and
     * ChangeOrderApplyService::apply(), for consistency across every consumer of this concept.
     */
    private function latestContract(Project $project, array $with = []): ?Contract
    {
        return Contract::query()
            ->where('project_id', $project->id)
            ->with($with)
            ->latest('signed_at')
            ->first();
    }

    private function collectedFor(Project $project): string
    {
        return BoqMoney::sumAccessor(
            Payment::query()->where('project_id', $project->id)->get(),
            'amount'
        );
    }

    private function appliedChangeOrderValueFor(Project $project): string
    {
        return BoqMoney::sumAccessor(
            ChangeOrder::query()->where('project_id', $project->id)->where('status', 'applied')->get(),
            'price_delta'
        );
    }
}
