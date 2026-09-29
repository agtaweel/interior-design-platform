<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Costs\ProjectCostCalculator;
use App\Services\Payments\ProjectFinancialsCalculator;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /projects/{project}/financials (PROJECT_CONTEXT.md Sprint 6, extended per BRD §8). {project}
 * follows the same manual-lookup convention as ProjectController itself — Project uses
 * BelongsToOrganization directly, so Project::find() is already tenant-scoped.
 *
 * Gated behind Permissions::VIEW_FINANCIALS (not just active membership) per this sprint's
 * explicit instruction — this is exactly the profit-adjacent dashboard data the locked product
 * decisions want kept away from roles like Site Staff (and, per the seeded RoleSeeder, Designer
 * too — VIEW_FINANCIALS is false there even though MANAGE_BOQ is true).
 *
 * `collected`/`outstanding` are computed via ProjectFinancialsCalculator, the SAME class
 * ProjectResource.financials uses internally — see that class's docblock for the
 * no-contract-yet outstanding=0 judgment call. `actual_cost`/`committed_cost`/`quoted_cost`/
 * `gross_profit`/`margin_percent` now come from ProjectCostCalculator (BRD §8/§9), which reads
 * real Procurement (PurchaseOrder/PurchaseOrderItem) and Expenses data — these were previously
 * hardcoded Sprint 6-era placeholders (0 / null) before those modules existed.
 */
class ProjectFinancialsController extends Controller
{
    public function __construct(
        private readonly ProjectFinancialsCalculator $calculator,
        private readonly ProjectCostCalculator $costCalculator,
    ) {}

    public function show(string $project): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $totals = $this->calculator->calculate($projectModel);
        $contract = Contract::query()->where('project_id', $projectModel->id)->latest('signed_at')->first();
        $costs = $this->costCalculator->calculate($projectModel, $contract);

        return response()->json([
            'data' => [
                // Same source as ProjectResource.financials.value: the project's cached
                // grand_total from Sprint 3's pricing recalculation. Null-coalesced to 0 for a
                // never-priced project, matching ProjectResource's identical fallback.
                'value' => $projectModel->grand_total ?? 0,
                'collected' => $totals['collected'],
                'outstanding' => $totals['outstanding'],
                'quoted_cost' => $costs['quoted_cost'],
                'committed_cost' => $costs['committed_cost'],
                'actual_cost' => $costs['actual_cost'],
                'gross_profit' => $costs['gross_profit'],
                'margin_percent' => $costs['margin_percent'],
            ],
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
                'details' => (object) [],
            ],
        ], 404);
    }
}
