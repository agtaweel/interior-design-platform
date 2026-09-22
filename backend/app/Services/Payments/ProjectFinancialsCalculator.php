<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\Project;
use App\Services\Boq\BoqMoney;

/**
 * Shared `collected`/`outstanding` computation for GET /projects/{id}/financials
 * (ProjectFinancialsController) AND the `financials` block embedded in GET /projects/{id}
 * (ProjectResource) — PROJECT_CONTEXT.md explicitly asks for the standalone endpoint's logic to
 * be reused rather than duplicated in the resource, so this is the one place either of those
 * two consumers computes these two numbers.
 *
 * `actual_cost`/`gross_profit` are deliberately NOT computed here — per PROJECT_CONTEXT.md's
 * Sprint 6 scope boundary, they stay at their placeholder values (0 / null) until a future
 * sprint adds real expense tracking; this class only owns the two numbers that ARE real this
 * sprint.
 */
final class ProjectFinancialsCalculator
{
    /**
     * @return array{collected: string|int, outstanding: string|int}
     */
    public function calculate(Project $project): array
    {
        $collected = BoqMoney::sumAccessor(
            Payment::query()->where('project_id', $project->id)->get(),
            'amount',
        );

        $outstanding = $this->resolveOutstanding($project, $collected);

        return [
            'collected' => $this->zeroAsInt($collected),
            'outstanding' => $this->zeroAsInt($outstanding),
        ];
    }

    /**
     * Matches the existing convention `ProjectResource.financials.value` already established
     * (`$this->grand_total ?? 0` — a bare int 0 when there's nothing to report, a bcmath
     * decimal string otherwise): a genuinely zero amount is surfaced as the plain int `0`
     * rather than the string `"0.00"`. This isn't just cosmetic — it's a pinned API contract:
     * tests/Feature/Projects/ClientPropertyProjectApiTest asserts
     * `financials.collected`/`.outstanding` are exactly `0` (strict) for a freshly-created
     * project with no payments/contract, predating this sprint. Any non-zero amount is still
     * returned as a full-precision bcmath decimal string, never a float.
     */
    private function zeroAsInt(string $amount): string|int
    {
        return bccomp($amount, BoqMoney::zero(), BoqMoney::SCALE) === 0 ? 0 : $amount;
    }

    /**
     * Judgment call (PROJECT_CONTEXT.md left this explicitly to backend-api-engineer,
     * "0/null-safe if no contract exists yet ... your call, document it"):
     *
     * If the project has no contract yet, `outstanding` is 0.00 — NOT the project's priced
     * `value`. Reasoning: "outstanding" is a RECEIVABLE — money the office is owed and can
     * chase — and per the locked product decision + this sprint's own framing ("receivables
     * dashboard"), nothing is actually owed until a contract exists to be owed against. A
     * priced-but-not-yet-contracted project has an estimated `value`, but surfacing that same
     * number as `outstanding` would misrepresent an estimate as a receivable before any client
     * has committed to it. Once a contract exists, `outstanding = contract_value - collected`,
     * clamped at 0.00 (never negative) so an overpayment reads as "fully settled" rather than a
     * confusing negative balance — this endpoint is a receivables view, not a running ledger.
     *
     * If a project somehow has more than one contract (nothing in this codebase prevents it at
     * the DB level, though Sprint 5's proposal_version_id uniqueness makes it unlikely in
     * practice), the most recently created one is used — "the project's contract" in the
     * singular, as PROJECT_CONTEXT.md's wording assumes.
     */
    private function resolveOutstanding(Project $project, string $collected): string
    {
        $contract = Contract::query()->where('project_id', $project->id)->latest('id')->first();

        if (! $contract) {
            return BoqMoney::zero();
        }

        $outstanding = bcsub((string) $contract->contract_value, $collected, BoqMoney::SCALE);

        return bccomp($outstanding, BoqMoney::zero(), BoqMoney::SCALE) < 0
            ? BoqMoney::zero()
            : $outstanding;
    }
}
