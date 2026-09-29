<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHandoverRequest;
use App\Http\Resources\HandoverResource;
use App\Models\Handover;
use App\Models\Project;
use App\Models\Snag;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;

/**
 * GET/POST /projects/{project}/handover, GET /projects/{project}/handover/pdf (BRD S20
 * "Handover: final approval, warranty, completion document"). One handover per project
 * (DB-unique project_id) — store() 409s if one already exists.
 *
 * Enforces the BRD's explicit rule: "Project cannot be marked complete with unresolved
 * mandatory snags" — a handover IS the completion act, so store() 409s if
 * Snag::hasOpenMandatorySnags() is true for this project (see that method's docblock; the same
 * check also gates ProjectController::update() for a direct status='completed' PATCH).
 */
class HandoverController extends Controller
{
    public function show(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $handover = Handover::query()->where('project_id', $projectModel->id)->with('approvedBy')->first();

        if (! $handover) {
            return $this->notFound();
        }

        return response()->json(['data' => new HandoverResource($handover)]);
    }

    public function store(StoreHandoverRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        if (Handover::query()->where('project_id', $projectModel->id)->exists()) {
            return $this->error(409, 'handover_already_exists', 'This project already has a handover recorded.');
        }

        if (Snag::hasOpenMandatorySnags($projectModel->id)) {
            return $this->error(
                409,
                'unresolved_mandatory_snags',
                'This project has unresolved mandatory snags and cannot be handed over yet.',
            );
        }

        $handover = Handover::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
            'approved_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => new HandoverResource($handover->fresh('approvedBy'))], 201);
    }

    public function pdf(string $project): HttpResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            abort(404);
        }

        $handover = Handover::query()->where('project_id', $projectModel->id)
            ->with(['approvedBy', 'project.client'])
            ->first();

        if (! $handover) {
            abort(404);
        }

        $filename = sprintf('handover-%s.pdf', $projectModel->code);

        return Pdf::loadView('handovers.pdf', ['handover' => $handover])->download($filename);
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
