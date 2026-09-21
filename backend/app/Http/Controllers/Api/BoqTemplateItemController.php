<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqTemplateItemRequest;
use App\Http\Resources\BoqTemplateItemResource;
use App\Models\BoqTemplateCategory;
use App\Models\BoqTemplateItem;
use Illuminate\Http\JsonResponse;

/**
 * POST /boq-templates/categories/{category}/items. BoqTemplateCategory::find() is
 * OrganizationScope-guarded (BelongsToOrganization), so resolving {category} here already
 * confirms it's in the current tenant — same pattern as ProjectServiceController resolving
 * {project} before creating a nested ProjectService.
 */
class BoqTemplateItemController extends Controller
{
    public function store(StoreBoqTemplateItemRequest $request, string $category): JsonResponse
    {
        $categoryModel = BoqTemplateCategory::find($category);

        if (! $categoryModel) {
            return $this->notFound();
        }

        $item = BoqTemplateItem::create([
            ...$request->validated(),
            'category_id' => $categoryModel->id,
            'organization_id' => $categoryModel->organization_id,
        ]);

        // fresh(): sort_order has a database-level default — same fresh() rationale as
        // BoqCategoryController::store().
        return response()->json([
            'data' => new BoqTemplateItemResource($item->fresh()),
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
