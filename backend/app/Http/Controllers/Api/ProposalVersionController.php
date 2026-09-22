<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalVersionRequest;
use App\Http\Requests\UpdateProposalVersionRequest;
use App\Http\Resources\ProposalVersionResource;
use App\Http\Resources\ProposalVersionSummaryResource;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Services\Proposals\ProposalPresenter;
use App\Services\Proposals\ProposalSendService;
use App\Services\Proposals\ProposalVersionService;
use App\Support\Authorization\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * POST/GET /projects/{project}/proposals, GET/PATCH /proposals/{proposal}, POST
 * /proposals/{proposal}/send, GET /proposals/{proposal}/pdf (PROJECT_CONTEXT.md Sprint 4).
 *
 * {project}/{proposal} follow the same manual-lookup convention as every other project-nested/
 * cross-cutting controller in this codebase (BoqItemController, PricingRuleController) — never
 * implicit route-model binding, since SubstituteBindings runs before the `tenant` middleware.
 * ProposalVersion carries no organization_id of its own (scoped indirectly via project_id ->
 * projects.organization_id, see model docblock), so resolveTenantScopedVersion() re-resolves
 * the owning project through the OrganizationScope-guarded Project::find() before treating a
 * row as belonging to this tenant — identical pattern to
 * PricingRuleController::resolveTenantScopedRule().
 *
 * Reads (index/show) require only an active membership; writes (store/update/send/pdf) require
 * Permissions::MANAGE_BOQ — see that constant's docblock for why proposals reuse it rather than
 * a new `manage_proposals` permission. `pdf()` is gated the same as `show()` (read-only, no
 * extra permission) since staff previewing a PDF is the same trust level as viewing the detail
 * page.
 */
class ProposalVersionController extends Controller
{
    public function __construct(
        private readonly ProposalVersionService $service,
        private readonly ProposalSendService $sendService,
        private readonly ProposalPresenter $presenter,
    ) {}

    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $versions = ProposalVersion::query()
            ->where('project_id', $projectModel->id)
            ->with('createdBy')
            ->orderByDesc('version_no')
            ->get();

        return response()->json([
            'data' => ProposalVersionSummaryResource::collection($versions),
        ]);
    }

    public function store(StoreProposalVersionRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $version = $this->service->createDraft(
            $projectModel,
            $request->validated('content_json'),
            $request->user()->id,
        );

        return response()->json([
            'data' => new ProposalVersionResource($version->loadMissing('createdBy')),
        ], 201);
    }

    public function show(string $proposal): JsonResponse
    {
        $version = $this->resolveTenantScopedVersion($proposal, ['items', 'createdBy']);

        if (! $version) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ProposalVersionResource($version),
        ]);
    }

    public function update(UpdateProposalVersionRequest $request, string $proposal): JsonResponse
    {
        $version = $this->resolveTenantScopedVersion($proposal);

        if (! $version) {
            return $this->notFound();
        }

        if ($version->status !== 'draft') {
            return $this->error(409, 'PROPOSAL_NOT_EDITABLE', 'Only draft proposal versions can be edited. Create a new version to make changes.');
        }

        $data = $request->validated();

        if (array_key_exists('content_json', $data)) {
            $version->update(['content_json' => $data['content_json']]);
        }

        if ($data['resnapshot'] ?? false) {
            $version = $this->service->resnapshot($version);
        }

        return response()->json([
            'data' => new ProposalVersionResource($version->fresh(['items', 'createdBy'])),
        ]);
    }

    public function send(string $proposal): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $version = $this->resolveTenantScopedVersion($proposal);

        if (! $version) {
            return $this->notFound();
        }

        // 409, not re-derived per-status: "already sent"/"already approved"/"changes
        // requested" all share the same underlying problem (this action only makes sense from
        // draft) and PROJECT_CONTEXT.md leaves the exact code to our judgment — one code keeps
        // the client-side handling simple (there is exactly one recovery: create a new draft
        // version) rather than forcing callers to switch on multiple near-identical codes.
        if ($version->status !== 'draft') {
            return $this->error(409, 'PROPOSAL_NOT_DRAFT', 'Only draft proposal versions can be sent.');
        }

        $result = $this->sendService->send($version);

        return response()->json([
            'data' => [
                'id' => $result['version']->id,
                'version_no' => $result['version']->version_no,
                'status' => $result['version']->status,
                'sent_at' => $result['version']->sent_at,
                // Internal-only response: staff copy these to relay via WhatsApp/email
                // manually (locked product decision, no WhatsApp Business API integration).
                'public_url' => $result['public_url'],
                'otp_code' => $result['otp_code'],
            ],
        ]);
    }

    public function pdf(string $proposal): HttpResponse
    {
        $version = $this->resolveTenantScopedVersion($proposal);

        if (! $version) {
            return $this->notFound();
        }

        $payload = $this->presenter->present($version);
        $filename = sprintf('proposal-%s-v%d.pdf', $payload['project']['code'] ?? $version->project_id, $version->version_no);

        return Pdf::loadView('proposals.pdf', ['data' => $payload])->download($filename);
    }

    /**
     * Fetches a ProposalVersion by id (un-scoped, since the model has no organization_id of
     * its own — see class docblock) and confirms it belongs to the current tenant by
     * re-resolving its project through the OrganizationScope-guarded Project::find(). Returns
     * null if the version doesn't exist OR belongs to another organization — callers must
     * treat both as 404.
     */
    private function resolveTenantScopedVersion(string $proposal, array $with = []): ?ProposalVersion
    {
        $version = ProposalVersion::query()->with($with)->find($proposal);

        if (! $version) {
            return null;
        }

        if (! Project::find($version->project_id)) {
            return null;
        }

        return $version;
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
