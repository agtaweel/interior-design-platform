<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectMemberRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\JsonResponse;

/**
 * POST /projects/{project}/members. AddProjectMemberRequest already validates that user_id
 * refers to an ACTIVE organization_members row in the project's own organization — this
 * controller only needs to resolve the project (tenant-scoped via OrganizationScope, same
 * pattern as ClientController/ProjectController) and guard against double-assignment.
 */
class ProjectMemberController extends Controller
{
    public function store(AddProjectMemberRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $data = $request->validated();

        $existing = ProjectMember::where('project_id', $projectModel->id)
            ->where('user_id', $data['user_id'])
            ->first();

        if ($existing) {
            return $this->error(409, 'member_already_assigned', 'This user is already a member of this project.');
        }

        $member = ProjectMember::create([
            'project_id' => $projectModel->id,
            'user_id' => $data['user_id'],
            'role' => $data['role'],
        ]);

        return response()->json([
            'data' => new ProjectMemberResource($member->fresh('user')),
        ], 201);
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'The requested resource was not found.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) [],
            ],
        ], $status);
    }
}
