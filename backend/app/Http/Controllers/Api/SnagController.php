<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSnagRequest;
use App\Http\Requests\UpdateSnagRequest;
use App\Http\Resources\SnagResource;
use App\Models\Project;
use App\Models\Snag;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /projects/{project}/snags, PATCH /snags/{snag}, POST /snags/{snag}/close|reopen
 * (BRD "Snagging": defects, priorities, owners, due dates, closure, warranty — S19).
 *
 * {project} follows the same manual-lookup convention as every other project-nested
 * controller; Snag has no organization_id of its own, resolved+tenant-checked the same way as
 * ProjectTask.
 *
 * Reads require only an active membership; mutations require Permissions::MANAGE_EXECUTION —
 * same persona as Tasks/Site Reports (a site engineer raises and closes snags as part of the
 * same day-to-day execution workflow).
 */
class SnagController extends Controller
{
    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $snags = Snag::query()
            ->where('project_id', $projectModel->id)
            ->with('owner')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => SnagResource::collection($snags)]);
    }

    public function store(StoreSnagRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $snag = Snag::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
        ]);

        return response()->json(['data' => new SnagResource($snag->fresh('owner'))], 201);
    }

    public function update(UpdateSnagRequest $request, string $snag): JsonResponse
    {
        $model = $this->resolveTenantScopedSnag($snag);

        if (! $model) {
            return $this->notFound();
        }

        $model->update($request->validated());

        return response()->json(['data' => new SnagResource($model->fresh('owner'))]);
    }

    public function close(Request $request, string $snag): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_EXECUTION);

        $model = $this->resolveTenantScopedSnag($snag);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->status === 'closed') {
            return $this->error(409, 'snag_already_closed', 'This snag is already closed.');
        }

        $model->forceFill([
            'status' => 'closed',
            'resolution_notes' => $request->input('resolution_notes'),
            'closed_at' => now(),
        ])->save();

        return response()->json(['data' => new SnagResource($model->fresh('owner'))]);
    }

    public function reopen(string $snag): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_EXECUTION);

        $model = $this->resolveTenantScopedSnag($snag);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->status === 'open') {
            return $this->error(409, 'snag_already_open', 'This snag is already open.');
        }

        $model->forceFill(['status' => 'open', 'closed_at' => null])->save();

        return response()->json(['data' => new SnagResource($model->fresh('owner'))]);
    }

    private function resolveTenantScopedSnag(string $snag): ?Snag
    {
        $model = Snag::query()->find($snag);

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
