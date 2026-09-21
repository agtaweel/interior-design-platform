<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBoqRequest;
use App\Models\Project;
use App\Services\Boq\BoqCsvExporter;
use App\Services\Boq\BoqTreeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /projects/{project}/boq and GET /projects/{project}/boq/export. Both are read
 * operations, so — matching IndexProjectsRequest/IndexClientsRequest — no extra permission
 * beyond an active membership is required (BOQ *writes* require Permissions::MANAGE_BOQ; see
 * BoqCategoryController/BoqItemController).
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
