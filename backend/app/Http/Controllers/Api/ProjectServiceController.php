<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectServiceRequest;
use App\Http\Resources\ProjectServiceResource;
use App\Models\Project;
use App\Models\ProjectService;
use Illuminate\Http\JsonResponse;

/**
 * POST /projects/{project}/services. ProjectService has no organization_id column of its own
 * (see model docblock) — tenant isolation here comes entirely from resolving $project through
 * the OrganizationScope-guarded Project::find() before creating anything under it.
 */
class ProjectServiceController extends Controller
{
    public function store(AddProjectServiceRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $service = ProjectService::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
        ]);

        // fresh(): metadata_json has a database-level default ('{}') that Eloquent doesn't
        // reflect on the in-memory instance when the field is omitted from the request — a
        // re-fetch shows the caller what was actually persisted.
        return response()->json([
            'data' => new ProjectServiceResource($service->fresh()),
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
