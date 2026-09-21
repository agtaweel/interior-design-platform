<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqCategoryRequest;
use App\Http\Resources\BoqCategoryResource;
use App\Models\BoqCategory;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * POST /projects/{project}/boq/categories. Permission (Permissions::MANAGE_BOQ) is checked
 * inside StoreBoqCategoryRequest::authorize(); this controller only needs to resolve the
 * project (tenant-scoped via OrganizationScope, same pattern as ProjectServiceController) and
 * create the row under it.
 */
class BoqCategoryController extends Controller
{
    public function store(StoreBoqCategoryRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $category = BoqCategory::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
        ]);

        // fresh(): sort_order has a database-level default (0) that Eloquent doesn't reflect
        // on the in-memory instance when the field is omitted from the request — same
        // rationale as ProjectServiceController::store()'s fresh() call.
        return response()->json([
            'data' => new BoqCategoryResource($category->fresh()),
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
