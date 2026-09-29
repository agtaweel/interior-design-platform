<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Services\Expenses\ExpenseRecordingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST/GET /projects/{project}/expenses, GET /expenses/{expense}/receipt (BRD "Expenses:
 * Project expenses, receipts, supplier linkage" — feeds the profitability engine's actual-cost
 * basis, see ProjectFinancialsCalculator).
 *
 * Permission split mirrors PaymentController exactly: store() (mutation) is gated behind
 * Permissions::MANAGE_BOQ (same commercial-workflow tier as BOQ/pricing/proposals/contracts/
 * payments); index()/receipt() (reads) are gated behind Permissions::VIEW_FINANCIALS since
 * expense amounts are exactly the profit-adjacent data that permission exists to protect.
 *
 * {project} follows the same manual-lookup convention as every other project-nested
 * controller. ProjectExpense carries organization_id directly (like Payment), so
 * ProjectExpense::find() is already tenant-scoped via BelongsToOrganization's global scope —
 * no manual re-resolution needed for {expense}.
 */
class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseRecordingService $recordingService) {}

    public function index(string $project): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $expenses = ProjectExpense::query()
            ->where('project_id', $projectModel->id)
            ->with('supplier')
            ->orderByDesc('expense_date')
            ->get();

        return response()->json(['data' => ExpenseResource::collection($expenses)]);
    }

    public function store(StoreExpenseRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $expense = $this->recordingService->record($projectModel, $request->validated(), $request->file('receipt'));

        return response()->json(['data' => new ExpenseResource($expense->load('supplier'))], 201);
    }

    public function receipt(Request $request, string $expense): StreamedResponse|JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $model = ProjectExpense::query()->find($expense);

        if (! $model || ! $model->receipt_url) {
            return $this->notFound();
        }

        if (! Storage::disk('local')->exists($model->receipt_url)) {
            return $this->notFound();
        }

        return Storage::disk('local')->response($model->receipt_url);
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
