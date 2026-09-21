<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoqTemplateCategory;
use App\Models\Project;
use App\Services\Boq\BoqTemplateCloner;
use App\Services\Boq\BoqTreeService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{project}/boq/apply-template/{templateCategory} — clones an organization BOQ
 * template category (and every nested child template category/item, recursively) into the
 * project's own boq_categories/boq_items as brand-new rows. See
 * App\Services\Boq\BoqTemplateCloner's docblock for why this is a copy, never a reference.
 *
 * No request body: this endpoint takes no payload (the whole template subtree is cloned
 * as-is), so there's no Form Request here — permission is checked directly via Gate::authorize,
 * same as BoqItemController::destroy().
 */
class BoqTemplateController extends Controller
{
    public function __construct(
        private readonly BoqTemplateCloner $cloner,
        private readonly BoqTreeService $treeService,
    ) {}

    public function apply(string $project, string $templateCategory): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        // BoqTemplateCategory uses BelongsToOrganization, so this ::find() is already
        // OrganizationScope-guarded — a template id from another organization resolves to
        // null here, same as every other cross-tenant lookup in this codebase.
        $templateCategoryModel = BoqTemplateCategory::find($templateCategory);

        if (! $templateCategoryModel) {
            return $this->notFound();
        }

        $newRootCategory = $this->cloner->applyToProject($templateCategoryModel, $projectModel);

        return response()->json([
            'data' => [
                'applied_category_id' => $newRootCategory->id,
                // The template subtree can touch many categories/items at once — returning the
                // project's freshly-rebuilt BOQ tree (rather than just the new node) saves the
                // frontend an extra round trip to see the merged result, matching
                // PROJECT_CONTEXT.md's "BOQ editing feels immediate" non-functional requirement.
                'boq' => $this->treeService->buildProjectTree($projectModel, false),
            ],
        ], 201);
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
