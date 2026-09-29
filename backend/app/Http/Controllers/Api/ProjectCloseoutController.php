<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForceCloseProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Closeout\CloseoutStateException;
use App\Services\Closeout\ProjectCloseoutService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{project}/closeout/start|check|close|force-close (BRD v3 §12 "Reconciliation &
 * Closeout"). {project} follows the same manual-lookup convention as every other project-nested
 * controller. start()/check()/close() require Permissions::MANAGE_BOQ (same tier as Contracts/
 * Payments/Change Orders); force-close() requires the dedicated
 * Permissions::MANAGE_FINANCIAL_CLOSEOUT instead (enforced inside ForceCloseProjectRequest) —
 * see that permission's docblock for why force-close is deliberately a stricter tier.
 */
class ProjectCloseoutController extends Controller
{
    public function __construct(private readonly ProjectCloseoutService $service) {}

    public function start(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        try {
            $updated = $this->service->startReconciliation($projectModel);
        } catch (CloseoutStateException $e) {
            return $this->conflict($e);
        }

        return response()->json(['data' => new ProjectResource($updated)]);
    }

    public function check(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        try {
            $result = $this->service->checkReadiness($projectModel);
        } catch (CloseoutStateException $e) {
            return $this->conflict($e);
        }

        return response()->json(['data' => $result]);
    }

    public function close(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        try {
            $updated = $this->service->close($projectModel, $request->user()->id);
        } catch (CloseoutStateException $e) {
            return $this->conflict($e);
        }

        return response()->json(['data' => new ProjectResource($updated)]);
    }

    public function forceClose(ForceCloseProjectRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        try {
            $updated = $this->service->forceClose($projectModel, $request->user()->id, $request->validated('reason'));
        } catch (CloseoutStateException $e) {
            return $this->conflict($e);
        }

        return response()->json(['data' => new ProjectResource($updated)]);
    }

    private function conflict(CloseoutStateException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => (object) [],
            ],
        ], 409);
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
