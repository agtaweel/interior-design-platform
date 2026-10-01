<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveClientDealRequest;
use App\Http\Requests\RequestChangesClientDealRequest;
use App\Http\Resources\PublicProposalResource;
use App\Models\ClientUser;
use App\Models\ProposalVersion;
use App\Services\Marketplace\ClientOwnershipResolver;
use App\Services\Proposals\ProposalApprovalService;
use App\Services\Proposals\ProposalPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /client/deals, GET /client/deals/{proposal}, POST /client/deals/{proposal}/approve,
 * POST /client/deals/{proposal}/request-changes (BRD v4 "Client Marketplace" — "deal" is a
 * relabeling of the existing Proposal system, not a new object; see ProposalApprovalService's
 * docblock for the shared approval/request-changes logic this reuses from the anonymous
 * OTP-link flow).
 *
 * Every lookup here goes through ClientOwnershipResolver, not a bare ProposalVersion::find() —
 * these routes sit outside the `tenant` middleware (see EnsureClientUser's docblock), so there
 * is no automatic query scoping to fall back on.
 *
 * index() hand-rolls its own minimal shape rather than reusing ProposalVersionSummaryResource,
 * which embeds `created_by` (an internal staff UserSummaryResource) — that resource is
 * staff-only and must never reach a client. show() reuses the already-audited
 * PublicProposalResource, same client-safe shape the anonymous portal uses.
 */
class ClientDealController extends Controller
{
    public function __construct(
        private readonly ClientOwnershipResolver $resolver,
        private readonly ProposalApprovalService $approvalService,
        private readonly ProposalPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();
        $projectIds = $this->resolver->projectsFor($clientUser)->pluck('id');

        $versions = ProposalVersion::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', '!=', 'draft')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $versions->map(fn (ProposalVersion $v) => [
                'id' => $v->id,
                'project_id' => $v->project_id,
                'version_no' => $v->version_no,
                'status' => $v->status,
                'sent_at' => $v->sent_at,
                'approved_at' => $v->approved_at,
                'grand_total' => $v->grand_total ?? 0,
            ])->values(),
        ]);
    }

    public function show(Request $request, string $proposal): JsonResponse
    {
        $version = $this->resolver->resolveProposalVersion($request->user(), $proposal);

        if (! $version) {
            return $this->notFound();
        }

        return response()->json(['data' => new PublicProposalResource($this->presenter->present($version))]);
    }

    public function approve(ApproveClientDealRequest $request, string $proposal): JsonResponse
    {
        $version = $this->resolver->resolveProposalVersion($request->user(), $proposal);

        if (! $version) {
            return $this->notFound();
        }

        if (config('fitout.marketplace_deal_requires_otp')) {
            // Kill-switch, not a feature toggle — see that config key's docblock. Flipping it
            // on disables the no-OTP shortcut immediately; it does not enable a replacement
            // OTP flow for logged-in clients (not built, out of scope until revisited).
            return $this->error(501, 'otp_approval_not_implemented', 'OTP-gated deal approval is not yet available for marketplace clients.');
        }

        if ($version->status === 'approved') {
            return $this->error(409, 'PROPOSAL_ALREADY_APPROVED', 'This proposal has already been approved.');
        }

        /** @var ClientUser $clientUser */
        $clientUser = $request->user();
        $comment = $this->composeComment($clientUser->name, $request->validated('comment'));

        $body = $this->approvalService->approve($version, $comment, $request->ip());

        return response()->json($body, 200);
    }

    public function requestChanges(RequestChangesClientDealRequest $request, string $proposal): JsonResponse
    {
        $version = $this->resolver->resolveProposalVersion($request->user(), $proposal);

        if (! $version) {
            return $this->notFound();
        }

        if ($version->status === 'approved') {
            return $this->error(409, 'PROPOSAL_ALREADY_APPROVED', 'This proposal has already been approved and can no longer be sent back for changes.');
        }

        /** @var ClientUser $clientUser */
        $clientUser = $request->user();
        $comment = $this->composeComment($clientUser->name, $request->validated('comment'));

        $this->approvalService->requestChanges($version, $comment, $request->ip());

        return response()->json(['data' => ['status' => 'changes_requested']]);
    }

    private function composeComment(string $name, ?string $comment): string
    {
        return $comment ? "{$name}: {$comment}" : $name;
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'The requested resource was not found.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) [],
            ],
        ], $status);
    }
}
