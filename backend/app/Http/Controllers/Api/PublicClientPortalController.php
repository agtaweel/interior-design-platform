<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovePublicChangeOrderRequest;
use App\Http\Requests\ApprovePublicProposalRequest;
use App\Http\Requests\RejectPublicChangeOrderRequest;
use App\Http\Requests\RequestChangesPublicProposalRequest;
use App\Http\Resources\ContractResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ProjectMediaResource;
use App\Http\Resources\PublicProposalResource;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Services\ChangeOrders\ChangeOrderClientDecision;
use App\Services\Payments\ProjectFinancialsCalculator;
use App\Services\Proposals\ProposalClientDecision;
use App\Services\Proposals\ProposalPresenter;
use App\Support\PublicLinks\SignedLinkException;
use App\Support\PublicLinks\SignedLinkService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /public/client-portal/{token}[/...] (BRD v3 §17 "Client Portal") — a real, multi-page,
 * read-only dashboard for the client, reusing the SAME token-authenticated-instead posture as
 * the public proposal/change-order surfaces (no auth:sanctum, no `tenant` middleware, see
 * routes/api.php). Unlike those single-purpose/single-use links, this token
 * (purpose='client_portal', issued by ClientPortalLinkController) is long-lived and multi-use —
 * verify() never consumes it, so the client can bookmark one URL and revisit it indefinitely
 * until staff revokes it.
 *
 * This class IS the client-safe leak-prevention boundary for every financial figure it
 * surfaces: financials() below exposes ONLY value/collected/outstanding (reusing
 * ProjectFinancialsCalculator, which already never computes cost/margin) — quoted_cost/
 * committed_cost/actual_cost/gross_profit/margin_percent (ProjectCostCalculator's output) are
 * NEVER reachable from any method in this controller, on purpose: those are supplier-cost/
 * markup/profit-adjacent numbers the BRD's own "never expose to a client" list names explicitly.
 * Every other endpoint here reuses an already-audited client-safe resource (PublicProposalResource/
 * PublicChangeOrderResource-shaped inline mapping/ContractResource/PaymentResource — none of
 * which carry a cost field to begin with, see their own docblocks) rather than inventing a new
 * shape that could accidentally reintroduce one.
 *
 * No supplier/procurement/expense data is exposed anywhere in this controller — Purchase
 * Orders, Invoice Documents, and Project Expenses have NO route here at all, deliberately: those
 * are cost-side records the client must never see, not just fields to scrub from a shared one.
 */
class PublicClientPortalController extends Controller
{
    private const PURPOSE = 'client_portal';

    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly ProjectFinancialsCalculator $financialsCalculator,
        private readonly ProposalPresenter $proposalPresenter,
        private readonly ProposalClientDecision $proposalDecision,
        private readonly ChangeOrderClientDecision $changeOrderDecision,
    ) {}

    public function overview(string $token): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $financials = $this->financialsCalculator->calculate($project);

        return response()->json([
            'data' => [
                'project' => [
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status,
                    'start_date' => $project->start_date,
                    'target_end_date' => $project->target_end_date,
                ],
                // Platform Readiness Review finding #06 — see class docblock's "organization"
                // note added alongside this fix.
                'organization' => ['currency' => $project->organization->currency],
                'financials' => [
                    'value' => $project->grand_total ?? 0,
                    'collected' => $financials['collected'],
                    'outstanding' => $financials['outstanding'],
                ],
                'counts' => [
                    'proposals' => ProposalVersion::query()->where('project_id', $project->id)->where('status', '!=', 'draft')->count(),
                    'change_orders' => ChangeOrder::query()->where('project_id', $project->id)->where('status', '!=', 'draft')->count(),
                    'payments' => Payment::query()->where('project_id', $project->id)->count(),
                ],
            ],
        ]);
    }

    /**
     * Summary list only (version_no/status/dates/grand_total) — the full client-shaped content
     * (line items, terms) is fetched per-version via show(), same "list is cheap, detail does
     * the real work" split ProjectCloseoutController's check()/close() don't need but a
     * multi-proposal project's portal does.
     */
    public function proposals(string $token): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $versions = ProposalVersion::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', 'draft')
            ->orderByDesc('version_no')
            ->get();

        return response()->json([
            'data' => $versions->map(fn (ProposalVersion $version) => [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'status' => $version->status,
                'sent_at' => $version->sent_at,
                'approved_at' => $version->approved_at,
                'grand_total' => $version->grand_total ?? 0,
            ])->values(),
            'organization' => ['currency' => $project->organization->currency],
        ]);
    }

    public function proposal(string $token, string $proposalVersion): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $version = $this->resolveProposalVersion($project, $proposalVersion);

        if (! $version) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new PublicProposalResource($this->proposalPresenter->present($version)),
        ]);
    }

    public function proposalPdf(string $token, string $proposalVersion): HttpResponse|JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $version = $this->resolveProposalVersion($project, $proposalVersion);

        if (! $version) {
            return $this->notFound();
        }

        $payload = $this->proposalPresenter->present($version);
        // Same scrub-at-the-boundary discipline as PublicProposalController::pdf() — the Blade
        // view must never receive the presenter's full cost/markup/fees breakdown.
        $clientPayload = (new PublicProposalResource($payload))->toArray(request());
        $filename = sprintf('proposal-%s-v%d.pdf', $payload['project']['code'] ?? $project->id, $version->version_no);

        return Pdf::loadView('proposals.pdf', ['data' => $clientPayload])->download($filename);
    }

    public function contract(string $token): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $contract = Contract::query()
            ->where('project_id', $project->id)
            ->with(['project.client', 'proposalVersion'])
            ->latest('signed_at')
            ->first();

        if (! $contract) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ContractResource($contract),
            'organization' => ['currency' => $project->organization->currency],
        ]);
    }

    public function payments(string $token): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $payments = Payment::query()->where('project_id', $project->id)->orderByDesc('paid_at')->get();

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'organization' => ['currency' => $project->organization->currency],
        ]);
    }

    /**
     * Same client-shaped item mapping PublicChangeOrderResource already established for the
     * single-change-order approval page (description/quantity/unit/old_unit_price/
     * new_unit_price/line_delta — boq_item_id and every other internal linkage always
     * stripped) — reproduced by hand here rather than reusing App\Http\Resources\
     * ChangeOrderItemResource, which IS internal-only (its own docblock says so explicitly) and
     * includes boq_item_id; that resource must never be used on this token-authenticated
     * public surface.
     */
    public function changeOrders(string $token): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $orders = ChangeOrder::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', 'draft')
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (ChangeOrder $order) => [
                'number' => $order->number,
                'status' => $order->status,
                'reason' => $order->reason,
                'timeline_delta_days' => $order->timeline_delta_days,
                'price_delta' => $order->price_delta,
                'items' => $order->items->map(fn (ChangeOrderItem $item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'old_unit_price' => $item->old_unit_price,
                    'new_unit_price' => $item->new_unit_price,
                    'line_delta' => $item->line_delta,
                ])->values(),
                'sent_at' => $order->sent_at,
                'approved_at' => $order->approved_at,
            ])->values(),
            'organization' => ['currency' => $project->organization->currency],
        ]);
    }

    /**
     * All three MEDIA_COLLECTIONS (designs/process/final_pictures) — every one of them is
     * already client-facing content by design (BRD "media gallery" feature), unlike Invoice
     * Documents/Purchase Orders, which have no route anywhere in this controller.
     */
    public function media(string $token): JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $media = $project->media()->orderByDesc('created_at')->get();

        return response()->json(['data' => ProjectMediaResource::collection($media)]);
    }

    public function mediaFile(Request $request, string $token, string $media): StreamedResponse|JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $mediaModel = $project->media()->where('id', $media)->first();

        if (! $mediaModel) {
            return $this->notFound();
        }

        return $mediaModel->toInlineResponse($request);
    }

    /**
     * Resolves the token to its Project, or the JsonResponse error to short-circuit with — same
     * "collapse every token failure reason to one generic 404" posture as
     * PublicProposalController::resolveVersionFromToken(), for the same enumerable-by-token
     * reasoning.
     */
    private function resolveProjectFromToken(string $token): Project|JsonResponse
    {
        try {
            $payload = $this->signedLinkService->verify($token, self::PURPOSE);
        } catch (SignedLinkException) {
            return $this->linkError();
        }

        $projectId = $payload['project_id'] ?? null;
        $project = $projectId ? Project::query()->find($projectId) : null;

        if (! $project) {
            return $this->linkError();
        }

        return $project;
    }

    /**
     * Confirms the requested proposal version actually belongs to the token's OWN project —
     * without this check, a valid client-portal token for project A could be used to fetch
     * proposal content from project B just by guessing/incrementing the id in the URL.
     */
    private function resolveProposalVersion(Project $project, string $proposalVersion): ?ProposalVersion
    {
        $version = ProposalVersion::query()->where('status', '!=', 'draft')->find($proposalVersion);

        if (! $version || $version->project_id !== $project->id) {
            return null;
        }

        return $version;
    }

    // --- Decisions inside the portal (docs/SIMPLIFICATION_REVIEW.md P3.1) ----------------------
    //
    // The client approves / requests changes / rejects right where they read the document, with
    // the 6-digit code staff sent them — so the portal link is the only link they ever need.
    // Exactly the same rules as the per-document links: both delegate to *ClientDecision, and the
    // code checked is the one issued with that document's own send link. Every document is
    // resolved inside the token's own project, so a portal token can't act on another project.

    public function approveProposal(ApprovePublicProposalRequest $request, string $token, string $proposalVersion): JsonResponse
    {
        $version = $this->decidableProposal($token, $proposalVersion);

        if ($version instanceof JsonResponse) {
            return $version;
        }

        [$status, $body] = $this->proposalDecision->approve(
            $version,
            $this->proposalDecision->sendLinkFor($version),
            $request->validated('otp'),
            $request->validated('name'),
            $request->validated('comment'),
            $request->ip(),
            $request->header('Idempotency-Key'),
        );

        return response()->json($body, $status);
    }

    public function requestProposalChanges(RequestChangesPublicProposalRequest $request, string $token, string $proposalVersion): JsonResponse
    {
        $version = $this->decidableProposal($token, $proposalVersion);

        if ($version instanceof JsonResponse) {
            return $version;
        }

        [$status, $body] = $this->proposalDecision->requestChanges(
            $version,
            $request->validated('name'),
            $request->validated('comment'),
            $request->ip(),
        );

        return response()->json($body, $status);
    }

    public function approveChangeOrder(ApprovePublicChangeOrderRequest $request, string $token, string $number): JsonResponse
    {
        $changeOrder = $this->decidableChangeOrder($token, $number);

        if ($changeOrder instanceof JsonResponse) {
            return $changeOrder;
        }

        [$status, $body] = $this->changeOrderDecision->approve(
            $changeOrder,
            $this->changeOrderDecision->sendLinkFor($changeOrder),
            $request->validated('otp'),
            $request->validated('name'),
            $request->validated('comment'),
            $request->ip(),
            $request->header('Idempotency-Key'),
        );

        return response()->json($body, $status);
    }

    public function rejectChangeOrder(RejectPublicChangeOrderRequest $request, string $token, string $number): JsonResponse
    {
        $changeOrder = $this->decidableChangeOrder($token, $number);

        if ($changeOrder instanceof JsonResponse) {
            return $changeOrder;
        }

        [$status, $body] = $this->changeOrderDecision->reject(
            $changeOrder,
            $request->validated('name'),
            $request->validated('comment'),
            $request->ip(),
        );

        return response()->json($body, $status);
    }

    /**
     * A proposal of the token's project that has been sent and not superseded by a decision the
     * client can no longer change. 'approved' passes through on purpose: the decision service
     * answers it with PROPOSAL_ALREADY_APPROVED *after* checking the code, the same ordering as
     * the per-document link.
     */
    private function decidableProposal(string $token, string $proposalVersion): ProposalVersion|JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $version = $this->resolveProposalVersion($project, $proposalVersion);

        if (! $version) {
            return $this->notFound();
        }

        if (! in_array($version->status, ['sent', 'approved'], true)) {
            return $this->error(409, 'PROPOSAL_NOT_AWAITING_DECISION', 'This proposal is not waiting for your decision.');
        }

        return $version->load('project');
    }

    /** Change orders are addressed by their number, as the portal list already shows them. */
    private function decidableChangeOrder(string $token, string $number): ChangeOrder|JsonResponse
    {
        $project = $this->resolveProjectFromToken($token);

        if ($project instanceof JsonResponse) {
            return $project;
        }

        $changeOrder = ChangeOrder::query()
            ->where('project_id', $project->id)
            ->where('number', $number)
            ->where('status', '!=', 'draft')
            ->with('project')
            ->first();

        if (! $changeOrder) {
            return $this->notFound();
        }

        if (! in_array($changeOrder->status, ['sent', 'approved', 'applied'], true)) {
            return $this->error(409, 'CHANGE_ORDER_NOT_AWAITING_DECISION', 'This change is not waiting for your decision.');
        }

        return $changeOrder;
    }

    private function linkError(): JsonResponse
    {
        return $this->error(404, 'client_portal_link_invalid', 'This client portal link is invalid, expired, or has been revoked.');
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
