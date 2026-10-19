<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectJourney;
use Illuminate\Http\JsonResponse;

/**
 * GET /projects/{project}/journey — the project's stage and next step (see ProjectJourney).
 * Same access rule as GET /projects/{project}: any active member of the project's organization
 * (tenant scoping via Project's OrganizationScope; another org's project is a 404). The payload
 * carries no money, so no financial permission is needed.
 */
class ProjectJourneyController extends Controller
{
    public function __construct(private readonly ProjectJourney $journey) {}

    public function show(string $project): JsonResponse
    {
        $model = Project::find($project);

        if (! $model) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                    'details' => (object) [],
                ],
            ], 404);
        }

        return response()->json(['data' => $this->journey->describe($model)]);
    }
}
