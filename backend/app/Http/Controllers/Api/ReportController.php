<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /reports/summary, GET /reports/projects, GET /reports/projects/export
 * (PROJECT_CONTEXT.md Sprint 8 "Reports" / S21). All three require Permissions::VIEW_FINANCIALS
 * — this is exactly the profit-adjacent data that permission exists to protect (same tier as
 * Sprint 6's payment schedules/payments/financials reads), via an explicit Gate::authorize()
 * call in each method (no FormRequest exists for any of these — no request body/query params to
 * validate).
 *
 * Not project-scoped — these are organization-wide, so unlike almost every other controller in
 * this codebase there's no {project} route parameter to manually resolve; ReportService derives
 * everything from the already-tenant-scoped Project/Payment query builders (see that class's
 * docblock).
 *
 * CSV export is its own route (`/reports/projects/export`), not a `?format=csv` query param on
 * the JSON route — mirrors BoqController's existing `/boq` vs `/boq/export` precedent
 * (BoqCsvExporter) rather than branching response type inside one action.
 */
class ReportController extends Controller
{
    /** Column order for the CSV export — same columns/order as the JSON per-project rows. */
    private const CSV_HEADER = [
        'name', 'code', 'status', 'contract_value', 'collected', 'outstanding',
        'estimated_margin', 'change_order_value', 'budget_variance',
        'quoted_cost', 'committed_cost', 'actual_cost', 'gross_profit', 'margin_percent',
    ];

    public function __construct(private readonly ReportService $service) {}

    public function summary(): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        return response()->json([
            'data' => $this->service->summary(),
        ]);
    }

    public function projects(): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        return response()->json([
            'data' => $this->service->projectRows(),
        ]);
    }

    public function projectsExport(): StreamedResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $rows = $this->service->projectRows();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::CSV_HEADER);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['name'],
                    $row['code'],
                    $row['status'],
                    $row['contract_value'],
                    $row['collected'],
                    $row['outstanding'],
                    $row['estimated_margin'],
                    $row['change_order_value'],
                    $row['budget_variance'],
                    $row['quoted_cost'],
                    $row['committed_cost'],
                    $row['actual_cost'],
                    $row['gross_profit'],
                    $row['margin_percent'],
                ]);
            }

            fclose($handle);
        }, 'project-financial-report.csv', ['Content-Type' => 'text/csv']);
    }
}
