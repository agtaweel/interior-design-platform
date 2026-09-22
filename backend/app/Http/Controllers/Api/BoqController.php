<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBoqRequest;
use App\Models\Project;
use App\Services\Boq\BoqCsvExporter;
use App\Services\Boq\BoqTreeService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /projects/{project}/boq and GET /projects/{project}/boq/export.
 *
 * PROJECT_CONTEXT.md Sprint 8 "Permissions hardening": both used to require only an active
 * membership, matching IndexProjectsRequest/IndexClientsRequest — but both responses include
 * material_unit_cost/labor_unit_cost/other_unit_cost (BoqTreeService/BoqCsvExporter), the exact
 * internal cost/margin data the Definition of Done says site users must never see. Both now
 * require Permissions::MANAGE_BOQ, matching every BOQ *write* endpoint's gate (see
 * BoqCategoryController/BoqItemController) — index() via IndexBoqRequest::authorize(), export()
 * (no FormRequest — nothing in the query string to validate) via an explicit Gate::authorize()
 * call, matching BoqItemController::destroy()'s pattern for body-less/request-less actions.
 */
class BoqController extends Controller
{
    public function __construct(private readonly BoqTreeService $treeService, private readonly BoqCsvExporter $exporter) {}

    public function index(IndexBoqRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $includeArchived = $request->boolean('include_archived');

        return response()->json([
            'data' => $this->treeService->buildProjectTree($projectModel, $includeArchived),
        ]);
    }

    public function export(string $project): StreamedResponse|JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $rows = $this->exporter->rowsForProject($projectModel);
        $filename = sprintf('boq-%s.csv', $projectModel->code ?? $projectModel->id);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, BoqCsvExporter::HEADER);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
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
