<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChangeOrderRequest;
use App\Http\Requests\UpdateChangeOrderRequest;
use App\Http\Resources\ChangeOrderResource;
use App\Http\Resources\ChangeOrderSummaryResource;
use App\Models\ChangeOrder;
use App\Models\Project;
use App\Services\ChangeOrders\ChangeOrderApplyException;
use App\Services\ChangeOrders\ChangeOrderApplyService;
use App\Services\ChangeOrders\ChangeOrderSendService;
use App\Services\ChangeOrders\ChangeOrderService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * POST/GET /projects/{project}/change-orders, GET/PATCH /change-orders/{changeOrder}, POST
 * /change-orders/{changeOrder}/send, POST /change-orders/{changeOrder}/apply
 * (PROJECT_CONTEXT.md Sprint 7).
 *
 * {project}/{changeOrder} follow the same manual-lookup convention as every other project-
 * nested/cross-cutting controller in this codebase (ProposalVersionController,
 * ContractController) — never implicit route-model binding, since SubstituteBindings runs
 * before the `tenant` middleware. ChangeOrder carries no organization_id of its own (scoped
 * indirectly via project_id -> projects.organization_id, see model docblock), so
 * resolveTenantScopedChangeOrder() re-resolves the owning project through the
 * OrganizationScope-guarded Project::find() before treating a row as belonging to this tenant —
 * identical pattern to ProposalVersionController::resolveTenantScopedVersion().
 *
 * Reads (index/show) require only an active membership; writes (store/update/send/apply)
 * require Permissions::MANAGE_BOQ — per PROJECT_CONTEXT.md's explicit instruction that
 * price_delta is a *pricing* concept (same tier as BOQ/pricing-rule/proposal/contract data),
 * not a *collected-money* concept, so this deliberately does NOT gate reads behind
 * VIEW_FINANCIALS the way Sprint 6's payment endpoints do.
 */
class ChangeOrderController extends Controller
{
    private const RELATIONS = ['items', 'requestedBy'];

    public function __construct(
        private readonly ChangeOrderService $service,
        private readonly ChangeOrderSendService $sendService,
        private readonly ChangeOrderApplyService $applyService,
    ) {}

    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $changeOrders = ChangeOrder::query()
            ->where('project_id', $projectModel->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => ChangeOrderSummaryResource::collection($changeOrders),
        ]);
    }

    public function store(StoreChangeOrderRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $changeOrder = $this->service->createDraft($projectModel, $request->validated(), $request->user()->id);

        return response()->json([
            'data' => new ChangeOrderResource($changeOrder->loadMissing(self::RELATIONS)),
        ], 201);
    }

    public function show(string $changeOrder): JsonResponse
    {
        $model = $this->resolveTenantScopedChangeOrder($changeOrder, self::RELATIONS);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ChangeOrderResource($model),
        ]);
    }

    public function update(UpdateChangeOrderRequest $request, string $changeOrder): JsonResponse
    {
        $model = $this->resolveTenantScopedChangeOrder($changeOrder);

        if (! $model) {
            return $this->notFound();
        }

        // Same "one code, one recovery" reasoning as PROPOSAL_NOT_EDITABLE — the only recovery
        // from any non-draft state is to create a new change order, so a single 409 code keeps
        // client-side handling simple.
        if ($model->status !== 'draft') {
            return $this->error(409, 'CHANGE_ORDER_NOT_EDITABLE', 'Only draft change orders can be edited. Create a new change order to make changes.');
        }

        $model = $this->service->updateDraft($model, $request->validated());

        return response()->json([
            'data' => new ChangeOrderResource($model->fresh(self::RELATIONS)),
        ]);
    }

    public function send(string $changeOrder): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $model = $this->resolveTenantScopedChangeOrder($changeOrder);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->status !== 'draft') {
            return $this->error(409, 'CHANGE_ORDER_NOT_DRAFT', 'Only draft change orders can be sent.');
        }

        $result = $this->sendService->send($model);

        return response()->json([
            'data' => [
                'id' => $result['change_order']->id,
                'number' => $result['change_order']->number,
                'status' => $result['change_order']->status,
                'sent_at' => $result['change_order']->sent_at,
                // Internal-only response: staff copy these to relay via WhatsApp/email
                // manually (locked product decision, no WhatsApp Business API integration) —
                // same shape as ProposalVersionController::send()'s response.
                'public_url' => $result['public_url'],
                'otp_code' => $result['otp_code'],
            ],
        ]);
    }

    /**
     * POST /change-orders/{id}/apply — only valid from 'approved' (see class docblock and
     * ChangeOrderApplyService for the actual BOQ/contract mutation). The one additional failure
     * mode beyond the status check — no contract exists yet for this project — is surfaced as
     * 422 CHANGE_ORDER_NO_CONTRACT (a business-rule precondition on a *related* resource, not a
     * state conflict on the change order itself, which is why it's 422 rather than another 409
     * alongside CHANGE_ORDER_NOT_APPROVED).
     */
    public function apply(string $changeOrder): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $model = $this->resolveTenantScopedChangeOrder($changeOrder);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->status !== 'approved') {
            return $this->error(409, 'CHANGE_ORDER_NOT_APPROVED', 'Only approved change orders can be applied.');
        }

        try {
            $model = $this->applyService->apply($model);
        } catch (ChangeOrderApplyException $e) {
            return $this->error(422, $e->errorCode, $e->getMessage());
        }

        return response()->json([
            'data' => new ChangeOrderResource($model->fresh(self::RELATIONS)),
        ]);
    }

    /**
     * Fetches a ChangeOrder by id (un-scoped, since the model has no organization_id of its own
     * — see class docblock) and confirms it belongs to the current tenant by re-resolving its
     * project through the OrganizationScope-guarded Project::find(). Returns null if the change
     * order doesn't exist OR belongs to another organization — callers must treat both as 404.
     */
    private function resolveTenantScopedChangeOrder(string $changeOrder, array $with = []): ?ChangeOrder
    {
        $model = ChangeOrder::query()->with($with)->find($changeOrder);

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

    private function error(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }
}
