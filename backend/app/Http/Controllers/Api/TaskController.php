<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Http\JsonResponse;

/**
 * GET/POST /projects/{project}/tasks, PATCH /tasks/{task} (BRD "Execution": tasks, assignee,
 * due date, photos — S16). {project} follows the same manual-lookup convention as every other
 * project-nested controller; ProjectTask has no organization_id of its own, so {task} is
 * resolved+tenant-checked in update() the same way as PurchaseOrder — walk project_id through
 * the org-scoped Project::find().
 *
 * Reads require only an active membership (execution progress is operational, not financial,
 * data); mutations require Permissions::MANAGE_EXECUTION — see that constant's docblock.
 */
class TaskController extends Controller
{
    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $tasks = ProjectTask::query()
            ->where('project_id', $projectModel->id)
            ->with(['assignee', 'media'])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => TaskResource::collection($tasks)]);
    }

    public function store(StoreTaskRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $data = $request->validated();

        $task = ProjectTask::create([
            'project_id' => $projectModel->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_user_id' => $data['assignee_user_id'] ?? null,
            'status' => $data['status'] ?? 'todo',
            'due_date' => $data['due_date'] ?? null,
        ]);

        foreach ($request->file('photos', []) as $photo) {
            $task->addMedia($photo)->toMediaCollection(ProjectTask::PHOTOS_COLLECTION);
        }

        return response()->json([
            'data' => new TaskResource($task->fresh(['assignee', 'media'])),
        ], 201);
    }

    public function update(UpdateTaskRequest $request, string $task): JsonResponse
    {
        $model = $this->resolveTenantScopedTask($task);

        if (! $model) {
            return $this->notFound();
        }

        $model->update($request->validated());

        return response()->json(['data' => new TaskResource($model->fresh(['assignee', 'media']))]);
    }

    private function resolveTenantScopedTask(string $task): ?ProjectTask
    {
        $model = ProjectTask::query()->find($task);

        if (! $model) {
            return null;
        }

        if (! Project::find($model->project_id)) {
            return null;
        }

        return $model;
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
