<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovePublicChangeOrderRequest;
use App\Http\Requests\RejectPublicChangeOrderRequest;
use App\Http\Resources\PublicChangeOrderResource;
use App\Models\ChangeOrder;
use App\Models\SignedLink;
use App\Services\ChangeOrders\ChangeOrderClientDecision;
use App\Services\ChangeOrders\ChangeOrderSendService;
use App\Support\PublicLinks\SignedLinkException;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Http\JsonResponse;

/**
 * GET /public/change-orders/{token}, POST /public/change-orders/{token}/approve, POST
 * /public/change-orders/{token}/reject (PROJECT_CONTEXT.md Sprint 7).
 *
 * Structurally copies PublicProposalController's approve()/token-resolution logic verbatim,
 * adapted for change_orders/entity_type 'change_order' — same OTP service
 * (App\Services\Proposals\OtpChallengeService, reused unchanged, not reimplemented), same
 * Idempotency-Key replay reconciliation (see that controller's approve() docblock for the full
 * "two seemingly-contradictory NFRs" reasoning, which applies identically here), same
 * collapse-all-token-errors-to-one-404 posture for an enumerable-by-token public surface.
 *
 * NO auth:sanctum, NO `tenant` middleware — token-authenticated instead
 * (SignedLinkService::verify(), purpose ChangeOrderSendService::PURPOSE). Carries the same
 * `public-links` rate limiter as the proposal public routes (see routes/api.php).
 *
 * Approving does NOT touch the BOQ or contract by default — that's the separate, staff-triggered
 * POST /change-orders/{id}/apply (ChangeOrderController::apply()), per PROJECT_CONTEXT.md's
 * explicit "approve != apply" design — unless the organization opted into auto-apply
 * (ChangeOrderAutoApplier, docs/SIMPLIFICATION_REVIEW.md P2.4). The decision logic itself lives in
 * ChangeOrderClientDecision, shared with approving from the client portal (P3.1).
 */
class PublicChangeOrderController extends Controller
{
    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly ChangeOrderClientDecision $decision,
    ) {}

    private const PURPOSE = ChangeOrderSendService::PURPOSE;

    public function show(string $token): JsonResponse
    {
        $changeOrder = $this->resolveFromToken($token);

        if ($changeOrder instanceof JsonResponse) {
            return $changeOrder;
        }

        return response()->json([
            'data' => new PublicChangeOrderResource($changeOrder->loadMissing(['items', 'project.organization'])),
        ]);
    }

    /**
     * See PublicProposalController::approve()'s docblock for the full idempotency-key
     * reconciliation write-up — the logic here is identical, just scoped to
     * "change_order_approve:{change_order_id}" instead of "proposal_approve:{...}".
     */
    public function approve(ApprovePublicChangeOrderRequest $request, string $token): JsonResponse
    {
        $changeOrder = $this->resolveFromToken($token, forMutation: true);

        if ($changeOrder instanceof JsonResponse) {
            return $changeOrder;
        }

        // Same rules as approving from the client portal — see ChangeOrderClientDecision.
        [$status, $body] = $this->decision->approve(
            $changeOrder,
            SignedLink::query()->where('token_hash', hash('sha256', $token))->first(),
            $request->validated('otp'),
            $request->validated('name'),
            $request->validated('comment'),
            $request->ip(),
            $request->header('Idempotency-Key'),
        );

        return response()->json($body, $status);
    }

    /**
     * No OTP, no idempotency-replay logic — mirrors PublicProposalController::requestChanges()'s
     * identical "lower stakes than approval" reasoning. A rejected change order is terminal
     * (PROJECT_CONTEXT.md: "staff creates a new one if they want to try again") — the guard
     * below prevents a reject call from clobbering an already-approved change order, same
     * posture as the request-changes-vs-approved guard on the proposal side.
     */
    public function reject(RejectPublicChangeOrderRequest $request, string $token): JsonResponse
    {
        $changeOrder = $this->resolveFromToken($token, forMutation: true);

        if ($changeOrder instanceof JsonResponse) {
            return $changeOrder;
        }

        [$status, $body] = $this->decision->reject(
            $changeOrder,
            $request->validated('name'),
            $request->validated('comment'),
            $request->ip(),
        );

        return response()->json($body, $status);
    }

    /**
     * Resolves a public token to its ChangeOrder, or returns the JsonResponse error to
     * short-circuit with. $forMutation additionally re-fetches with the `project` relation
     * eager-loaded (needed for organization_id/project_id on the approve/reject writes) —
     * show() doesn't need it. Same collapse-all-token-errors-to-404 posture as
     * PublicProposalController::resolveVersionFromToken().
     */
    private function resolveFromToken(string $token, bool $forMutation = false): ChangeOrder|JsonResponse
    {
        try {
            $payload = $this->signedLinkService->verify($token, self::PURPOSE);
        } catch (SignedLinkException) {
            return $this->linkError();
        }

        $changeOrderId = $payload['change_order_id'] ?? null;
        $changeOrder = $changeOrderId
            ? ChangeOrder::query()->when($forMutation, fn ($q) => $q->with('project'))->find($changeOrderId)
            : null;

        if (! $changeOrder) {
            return $this->linkError();
        }

        return $changeOrder;
    }

    private function linkError(): JsonResponse
    {
        return $this->error(404, 'change_order_link_invalid', 'This change order link is invalid, expired, or has been revoked.');
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
