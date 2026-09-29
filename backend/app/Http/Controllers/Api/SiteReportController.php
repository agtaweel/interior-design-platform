<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteReportRequest;
use App\Http\Resources\SiteReportResource;
use App\Models\Project;
use App\Models\SiteReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;

/**
 * GET/POST /projects/{project}/site-reports, GET /site-reports/{report},
 * GET /site-reports/{report}/pdf (BRD S17 "Site Report: work done, issues, decisions, photos,
 * PDF"). {project} follows the same manual-lookup convention as every other project-nested
 * controller; SiteReport has no organization_id of its own, resolved+tenant-checked the same
 * way as ProjectTask/PurchaseOrder.
 *
 * Reads require only an active membership; store() requires Permissions::MANAGE_EXECUTION
 * (checked inside StoreSiteReportRequest).
 */
class SiteReportController extends Controller
{
    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $reports = SiteReport::query()
            ->where('project_id', $projectModel->id)
            ->with(['reportedBy', 'media'])
            ->orderByDesc('report_date')
            ->get();

        return response()->json(['data' => SiteReportResource::collection($reports)]);
    }

    public function store(StoreSiteReportRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $data = $request->validated();

        $report = SiteReport::create([
            'project_id' => $projectModel->id,
            'reported_by_user_id' => $request->user()->id,
            'report_date' => $data['report_date'],
            'work_done' => $data['work_done'],
            'issues' => $data['issues'] ?? null,
            'decisions' => $data['decisions'] ?? null,
        ]);

        foreach ($request->file('photos', []) as $photo) {
            $report->addMedia($photo)->toMediaCollection(SiteReport::PHOTOS_COLLECTION);
        }

        return response()->json([
            'data' => new SiteReportResource($report->fresh(['reportedBy', 'media'])),
        ], 201);
    }

    public function show(string $report): JsonResponse
    {
        $model = $this->resolveTenantScopedReport($report);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json(['data' => new SiteReportResource($model->load(['reportedBy', 'media']))]);
    }

    public function pdf(string $report): HttpResponse
    {
        $model = $this->resolveTenantScopedReport($report);

        if (! $model) {
            abort(404);
        }

        $model->load(['project', 'reportedBy', 'media']);

        $filename = sprintf('site-report-%s.pdf', $model->id);

        return Pdf::loadView('site_reports.pdf', ['report' => $model])->download($filename);
    }

    private function resolveTenantScopedReport(string $report): ?SiteReport
    {
        $model = SiteReport::query()->with('media')->find($report);

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
