<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Services\Contracts\ContractPresenter;
use App\Services\Contracts\ContractService;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * POST /projects/{project}/contracts/from-proposal/{proposal}, GET/PATCH /contracts/{contract},
 * GET /contracts/{contract}/pdf (PROJECT_CONTEXT.md Sprint 5).
 *
 * {project}/{proposal}/{contract} all follow the same manual-lookup convention as every other
 * project-nested/cross-cutting controller in this codebase (ProposalVersionController,
 * PricingRuleController) — never implicit route-model binding, since SubstituteBindings runs
 * before the `tenant` middleware. Contract carries no organization_id of its own (scoped
 * indirectly via project_id -> projects.organization_id, see model docblock), so
 * resolveTenantScopedContract() re-resolves the owning project through the
 * OrganizationScope-guarded Project::find() before treating a row as belonging to this tenant —
 * identical pattern to ProposalVersionController::resolveTenantScopedVersion().
 *
 * Permission: reuses Permissions::MANAGE_BOQ for the one mutating action this controller has
 * (from-proposal conversion) and for update(), per PROJECT_CONTEXT.md's explicit instruction to
 * keep the commercial workflow's permission story consistent with Sprint 4's proposals rather
 * than introducing a `manage_contracts` permission. Reads (show/pdf) require only an active
 * membership, matching every other read endpoint in this codebase.
 */
class ContractController extends Controller
{
    private const RELATIONS = ['project.client', 'proposalVersion'];

    public function __construct(
        private readonly ContractService $service,
        private readonly ContractPresenter $presenter,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * GET /projects/{project}/contracts. Mirrors ProposalVersionController::index()'s shape
     * exactly: manual project lookup (404 if missing/cross-tenant), read access needs only an
     * active membership (no Gate::authorize call), plain array response ordered by created_at.
     *
     * In practice there will be 0 or 1 rows today — proposal_version_id is DB-unique (see
     * Contract model docblock) and a project typically has one active/approved proposal — but
     * this deliberately returns an array rather than assuming exactly one, since nothing here
     * enforces "only one proposal per project" at the project level.
     */
    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $contracts = Contract::query()
            ->where('project_id', $projectModel->id)
            ->with(self::RELATIONS)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => ContractResource::collection($contracts),
        ]);
    }

    /**
     * Business-rule preconditions checked here (not in a FormRequest — there's no request body
     * to validate, matching ProposalVersionController::send()'s identical shape):
     *  - the proposal version must belong to THIS project (404 if not — same "don't reveal
     *    cross-tenant/cross-project existence" posture as every other 404 in this codebase);
     *  - its status must be exactly 'approved'. Chosen response: 409 PROPOSAL_NOT_APPROVED, not
     *    422. Reasoning: 422 (PROJECT_CONTEXT.md's "business-rule validation") fits malformed/
     *    invalid INPUT, but nothing about this request's input is invalid — the proposalId is a
     *    real, valid reference; the problem is the RESOURCE'S CURRENT STATE conflicting with the
     *    action ("can't convert a draft/sent/changes_requested proposal"). That's exactly the
     *    409 "state conflict" case PROJECT_CONTEXT.md's own error-code table calls out, and
     *    matches this codebase's existing precedent for the identical shape of decision
     *    (PROPOSAL_NOT_DRAFT / PROPOSAL_NOT_EDITABLE in ProposalVersionController are both 409,
     *    not 422, for the same "valid input, wrong current state" reasoning);
     *  - no contract may already exist for it: 409 CONTRACT_ALREADY_EXISTS, per
     *    PROJECT_CONTEXT.md's explicit instruction (this one is spelled out verbatim in the
     *    spec, unlike the PROPOSAL_NOT_APPROVED code name above, which is this controller's own
     *    choice).
     */
    public function fromProposal(string $project, string $proposal): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $proposalVersion = ProposalVersion::find($proposal);

        if (! $proposalVersion || $proposalVersion->project_id !== $projectModel->id) {
            return $this->notFound();
        }

        if ($proposalVersion->status !== 'approved') {
            return $this->error(409, 'PROPOSAL_NOT_APPROVED', 'Only an approved proposal version can be converted to a contract.');
        }

        $existingContract = $proposalVersion->contract()->first();

        if ($existingContract) {
            return $this->error(409, 'CONTRACT_ALREADY_EXISTS', 'A contract already exists for this proposal version.', ['contract_id' => $existingContract->id]);
        }

        $contract = $this->service->fromProposal($projectModel, $proposalVersion);

        // Fired after the (already-transactional, see ContractService::fromProposal()) creation
        // commits, not inside its retry-loop transaction — a notification is not itself a
        // commercial mutation that needs to share the contract's atomicity/retry semantics, and
        // keeping it out here avoids a duplicate notification firing on a contract_no-conflict
        // retry attempt.
        $this->notificationService->notify($projectModel, 'contract_created', [
            'project_id' => $projectModel->id,
            'project_name' => $projectModel->name,
            'contract_id' => $contract->id,
            'contract_no' => $contract->contract_no,
            'summary' => sprintf('Contract %s created for %s.', $contract->contract_no, $projectModel->name),
        ]);

        return response()->json([
            'data' => new ContractResource($contract->fresh(self::RELATIONS)),
        ], 201);
    }

    public function show(string $contract): JsonResponse
    {
        $model = $this->resolveTenantScopedContract($contract, self::RELATIONS);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ContractResource($model),
        ]);
    }

    public function update(UpdateContractRequest $request, string $contract): JsonResponse
    {
        $model = $this->resolveTenantScopedContract($contract);

        if (! $model) {
            return $this->notFound();
        }

        // validated() only ever contains start_date/end_date/terms_json — contract_value/
        // proposal_version_id are `prohibited` in UpdateContractRequest, so a request that
        // includes either has already failed with 422 before reaching here (see that class's
        // docblock for why reject-over-strip was chosen).
        $model->update($request->validated());

        return response()->json([
            'data' => new ContractResource($model->fresh(self::RELATIONS)),
        ]);
    }

    public function pdf(string $contract): HttpResponse
    {
        $model = $this->resolveTenantScopedContract($contract);

        if (! $model) {
            return $this->notFound();
        }

        $payload = $this->presenter->present($model);
        $filename = sprintf('contract-%s.pdf', $payload['contract']['contract_no'] ?? $model->id);

        return Pdf::loadView('contracts.pdf', ['data' => $payload])->download($filename);
    }

    /**
     * Fetches a Contract by id (un-scoped, since the model has no organization_id of its own —
     * see class docblock) and confirms it belongs to the current tenant by re-resolving its
     * project through the OrganizationScope-guarded Project::find(). Returns null if the
     * contract doesn't exist OR belongs to another organization — callers must treat both as
     * 404, never leaking which case it was.
     */
    private function resolveTenantScopedContract(string $contract, array $with = []): ?Contract
    {
        $model = Contract::query()->with($with)->find($contract);

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
