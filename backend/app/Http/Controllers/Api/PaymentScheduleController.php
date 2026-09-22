<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentScheduleRequest;
use App\Http\Resources\PaymentScheduleResource;
use App\Models\Contract;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Services\Payments\PaymentScheduleService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * POST/GET /contracts/{contract}/payment-schedules (PROJECT_CONTEXT.md Sprint 6).
 *
 * {contract} follows the same manual-lookup convention as every other cross-cutting controller
 * in this codebase (ContractController, ProposalVersionController) — never implicit route-model
 * binding, since SubstituteBindings runs before the `tenant` middleware. Contract itself carries
 * no organization_id (scoped via project_id -> projects.organization_id), so
 * resolveTenantScopedContract() re-resolves the owning project through the
 * OrganizationScope-guarded Project::find(), identical to ContractController's own method of
 * the same name.
 *
 * Permission split, per PROJECT_CONTEXT.md's explicit instruction for this sprint: store()
 * (mutation) is gated behind Permissions::MANAGE_BOQ inside StorePaymentScheduleRequest
 * (consistent with every other Store*Request in this codebase); index() (read) is gated behind
 * Permissions::VIEW_FINANCIALS via an explicit Gate::authorize() call — this is the first read
 * endpoint in the codebase to require more than active membership, since payment schedules are
 * exactly the profit-adjacent data the locked product decisions care about protecting from
 * roles like Site Staff.
 */
class PaymentScheduleController extends Controller
{
    public function __construct(private readonly PaymentScheduleService $service) {}

    public function store(StorePaymentScheduleRequest $request, string $contract): JsonResponse
    {
        $contractModel = $this->resolveTenantScopedContract($contract);

        if (! $contractModel) {
            return $this->notFound();
        }

        $schedule = $this->service->create($contractModel, $request->validated());

        return response()->json([
            'data' => new PaymentScheduleResource($schedule),
        ], 201);
    }

    /**
     * Ordered by sequence_no per PROJECT_CONTEXT.md's explicit instruction — this is the
     * installment plan's natural display order, distinct from due_date (which usually but not
     * necessarily agrees with sequence_no).
     */
    public function index(string $contract): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $contractModel = $this->resolveTenantScopedContract($contract);

        if (! $contractModel) {
            return $this->notFound();
        }

        $schedules = PaymentSchedule::query()
            ->where('contract_id', $contractModel->id)
            ->orderBy('sequence_no')
            ->get();

        return response()->json([
            'data' => PaymentScheduleResource::collection($schedules),
        ]);
    }

    /**
     * Fetches a Contract by id (un-scoped, since the model has no organization_id of its own)
     * and confirms it belongs to the current tenant by re-resolving its project through the
     * OrganizationScope-guarded Project::find(). Returns null if the contract doesn't exist OR
     * belongs to another organization — callers must treat both as 404, never leaking which
     * case it was. Identical to ContractController::resolveTenantScopedContract().
     */
    private function resolveTenantScopedContract(string $contract): ?Contract
    {
        $model = Contract::query()->find($contract);

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
