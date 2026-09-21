<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqItemRequest;
use App\Http\Requests\UpdateBoqItemRequest;
use App\Http\Resources\BoqItemResource;
use App\Models\BoqItem;
use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{project}/boq/items, PATCH /boq/items/{item}, DELETE /boq/items/{item}
 * (archive, not hard delete — PROJECT_CONTEXT.md Sprint 2 API scope).
 *
 * PATCH/DELETE are deliberately NOT nested under /projects/{project} (see routes/api.php), so
 * {item} is resolved manually and its project re-derived from it — never via implicit
 * route-model binding. Sprint 1 already established why: Laravel's SubstituteBindings
 * middleware (implicit binding) runs BEFORE the `tenant` middleware in the priority-sorted
 * pipeline, so an implicitly-bound BoqItem would be fetched before OrganizationScope is even
 * active, and BoqItem itself carries no organization_id to scope by directly anyway (it's
 * scoped indirectly via project_id -> projects.organization_id). The pattern here matches
 * ProjectController/ClientController's manual `::find($param)` lookups exactly: fetch the item
 * un-scoped (there's nothing to scope BoqItem by), then re-resolve its project through the
 * OrganizationScope-guarded Project::find() — if that comes back null, the item belongs to a
 * different organization and this request treats it as 404, never leaking that the id exists
 * elsewhere.
 */
class BoqItemController extends Controller
{
    public function store(StoreBoqItemRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $item = BoqItem::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
        ]);

        return response()->json([
            'data' => new BoqItemResource($item->fresh()),
        ], 201);
    }

    public function update(UpdateBoqItemRequest $request, string $item): JsonResponse
    {
        $itemModel = $this->resolveTenantScopedItem($item);

        if (! $itemModel) {
            return $this->notFound();
        }

        $itemModel->update($request->validated());

        return response()->json([
            'data' => new BoqItemResource($itemModel->fresh()),
        ]);
    }

    public function destroy(string $item): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $itemModel = $this->resolveTenantScopedItem($item);

        if (! $itemModel) {
            return $this->notFound();
        }

        if ($itemModel->archived_at === null) {
            $itemModel->update(['archived_at' => now()]);
        }

        return response()->json([
            'data' => new BoqItemResource($itemModel->fresh()),
        ]);
    }

    /**
     * Fetches a BoqItem by id (un-scoped, since the model has no organization_id of its own —
     * see class docblock) and confirms it belongs to the current tenant by re-resolving its
     * project through the OrganizationScope-guarded Project::find(). Returns null if the item
     * doesn't exist OR belongs to another organization — callers must treat both as 404.
     */
    private function resolveTenantScopedItem(string $item): ?BoqItem
    {
        $itemModel = BoqItem::find($item);

        if (! $itemModel) {
            return null;
        }

        if (! Project::find($itemModel->project_id)) {
            return null;
        }

        return $itemModel;
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
