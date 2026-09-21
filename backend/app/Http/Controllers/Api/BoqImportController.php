<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportBoqRequest;
use App\Models\Project;
use App\Services\Boq\BoqCsvImporter;
use Illuminate\Http\JsonResponse;

/**
 * POST /projects/{project}/boq/import. Row-level parsing/validation and the transactional
 * multi-row write live in App\Services\Boq\BoqCsvImporter — this controller only resolves the
 * tenant-scoped project and returns the import summary.
 */
class BoqImportController extends Controller
{
    public function __construct(private readonly BoqCsvImporter $importer) {}

    public function import(ImportBoqRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $summary = $this->importer->import($projectModel, $request->file('file'));

        return response()->json([
            'data' => $summary,
        ]);
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
