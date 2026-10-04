<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoqItemResource;
use App\Models\BoqCatalogItem;
use App\Models\BoqTemplateVersion;
use App\Models\Project;
use App\Services\Boq\BoqTemplateCommitService;
use App\Services\Boq\BoqTemplatePreviewService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{project}/boq/template-preview and /template-commit — the composable
 * apply-template(s)-to-a-project flow that replaces the old single-shot
 * apply-template/{templateCategory} route (BoqTemplateController@apply, deleted along with
 * BoqTemplateCategory). Both actions are gated inline via Gate::authorize() rather than a
 * FormRequest, matching BoqItemController::destroy()'s convention for actions whose body shape
 * is a free-form array rather than a fixed set of fields — see BoqTemplatePreviewService and
 * BoqTemplateCommitService for the actual composition/persistence logic this controller just
 * wires up.
 */
class BoqTemplateApplyController extends Controller
{
    public function __construct(
        private readonly BoqTemplatePreviewService $previewService,
        private readonly BoqTemplateCommitService $commitService,
    ) {}

    public function preview(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $selections = $request->input('selections');

        if (! is_array($selections) || empty($selections)) {
            return $this->validationError('At least one template version selection is required.');
        }

        $organizationId = app(TenantContext::class)->organizationId();

        foreach ($selections as $selection) {
            if (empty($selection['template_version_id'])) {
                return $this->validationError('Each selection requires a template_version_id.');
            }

            if (! $this->versionIsAccessible((int) $selection['template_version_id'], $organizationId)) {
                return $this->notFound();
            }
        }

        $preview = $this->previewService->preview($selections, $organizationId);

        return response()->json(['data' => $preview]);
    }

    public function commit(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $items = $request->input('items');

        if (! is_array($items) || empty($items)) {
            return $this->validationError('At least one resolved item is required.');
        }

        $organizationId = app(TenantContext::class)->organizationId();

        $catalogItemIds = collect($items)->pluck('catalog_item_id')->filter()->unique();
        $catalogDefaultUnits = BoqCatalogItem::withoutGlobalScopes()->with('defaultUnit')
            ->whereIn('id', $catalogItemIds)
            ->get()
            ->mapWithKeys(fn (BoqCatalogItem $item) => [$item->id => $item->defaultUnit?->code]);

        foreach ($items as $row) {
            if (empty($row['catalog_item_id']) || ! is_numeric($row['quantity'] ?? null)) {
                return $this->validationError('Each item requires catalog_item_id and a numeric quantity.');
            }

            if (empty($row['unit']) && empty($catalogDefaultUnits->get($row['catalog_item_id']))) {
                return $this->validationError("Item {$row['catalog_item_id']} has no default unit in the catalog — a unit must be supplied explicitly.");
            }

            if (! empty($row['source_template_version_id']) && ! $this->versionIsAccessible((int) $row['source_template_version_id'], $organizationId)) {
                return $this->validationError('A resolved item references a template version that is not accessible.');
            }
        }

        $createdItems = $this->commitService->commit($projectModel, $items, $request->user());

        return response()->json(['data' => BoqItemResource::collection($createdItems)->resolve()], 201);
    }

    /** A template version is accessible if it's global/system, or belongs to the caller's own organization. */
    private function versionIsAccessible(int $versionId, ?int $organizationId): bool
    {
        $version = BoqTemplateVersion::withoutGlobalScopes()->with('template')->find($versionId);

        if (! $version || ! $version->template) {
            return false;
        }

        $templateOrgId = $version->template->organization_id;

        return $templateOrgId === null || $templateOrgId === $organizationId;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.', 'details' => (object) []],
        ], 404);
    }

    private function validationError(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'validation_error', 'message' => $message, 'details' => (object) []],
        ], 422);
    }
}
