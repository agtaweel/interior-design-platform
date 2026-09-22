<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Payments\ProjectFinancialsCalculator;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /projects/{project}/financials (PROJECT_CONTEXT.md Sprint 6). {project} follows the same
 * manual-lookup convention as ProjectController itself — Project uses BelongsToOrganization
 * directly, so Project::find() is already tenant-scoped.
 *
 * Gated behind Permissions::VIEW_FINANCIALS (not just active membership) per this sprint's
 * explicit instruction — this is exactly the profit-adjacent dashboard data the locked product
 * decisions want kept away from roles like Site Staff (and, per the seeded RoleSeeder, Designer
 * too — VIEW_FINANCIALS is false there even though MANAGE_BOQ is true).
 *
 * `collected`/`outstanding` are computed via ProjectFinancialsCalculator, the SAME class
 * ProjectResource.financials uses internally — see that class's docblock for the
 * no-contract-yet outstanding=0 judgment call. `actual_cost`/`gross_profit` stay at their
 * documented Sprint 6 scope-boundary placeholders (0 / null) — no expense tracking exists yet.
 */
class ProjectFinancialsController extends Controller
{
    public function __construct(private readonly ProjectFinancialsCalculator $calculator) {}

    public function show(string $project): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $totals = $this->calculator->calculate($projectModel);

        return response()->json([
            'data' => [
                // Same source as ProjectResource.financials.value: the project's cached
                // grand_total from Sprint 3's pricing recalculation. Null-coalesced to 0 for a
                // never-priced project, matching ProjectResource's identical fallback.
                'value' => $projectModel->grand_total ?? 0,
                'collected' => $totals['collected'],
                'outstanding' => $totals['outstanding'],
                'actual_cost' => 0,
                'gross_profit' => null,
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
