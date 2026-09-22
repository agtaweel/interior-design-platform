<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovePublicChangeOrderRequest;
use App\Http\Requests\RejectPublicChangeOrderRequest;
use App\Http\Resources\PublicChangeOrderResource;
use App\Models\Approval;
use App\Models\ChangeOrder;
use App\Models\IdempotencyKey;
use App\Models\OtpChallenge;
use App\Models\SignedLink;
use App\Services\ChangeOrders\ChangeOrderSendService;
use App\Services\Proposals\OtpChallengeService;
use App\Services\Proposals\OtpVerificationException;
use App\Support\PublicLinks\SignedLinkException;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
 * Approving does NOT touch the BOQ or contract — that's the separate, staff-triggered
 * POST /change-orders/{id}/apply (ChangeOrderController::apply()), per PROJECT_CONTEXT.md's
 * explicit "approve != apply" design (gives staff a deliberate checkpoint before the
 * BOQ/contract actually change).
 */
class PublicChangeOrderController extends Controller
{
    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly OtpChallengeService $otpService,
    ) {}

    private const PURPOSE = ChangeOrderSendService::PURPOSE;

    public function show(string $token): JsonResponse
    {
        $changeOrder = $this->resolveFromToken($token);

        if ($changeOrder instanceof JsonResponse) {
            return $changeOrder;
        }

        return response()->json([
            'data' => new PublicChangeOrderResource($changeOrder->loadMissing('items')),
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

        $scope = "change_order_approve:{$changeOrder->id}";
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $stored = IdempotencyKey::query()->where('scope', $scope)->where('key', $idempotencyKey)->first();

            if ($stored) {
                return response()->json($stored->response_body, $stored->response_status);
            }
        }

        $link = SignedLink::query()->where('token_hash', hash('sha256', $token))->first();
        $challenge = $link ? OtpChallenge::query()->where('signed_link_id', $link->id)->latest('id')->first() : null;

        if (! $link || ! $challenge) {
            // Data-integrity fallback only — every 'sent' change order gets exactly one signed
            // link + one otp_challenge in the same transaction (ChangeOrderSendService::send()),
            // so this branch should be unreachable in practice.
            return $this->linkError();
        }

        try {
            $this->otpService->verify($challenge, $request->validated('otp'));
        } catch (OtpVerificationException $e) {
            return match ($e->reason) {
                'locked' => $this->error(422, 'OTP_LOCKED', 'Too many incorrect attempts. This change order can no longer be approved from this link.'),
                'expired' => $this->error(422, 'OTP_EXPIRED', 'This verification code has expired.'),
                default => $this->error(422, 'OTP_INVALID', 'The verification code is incorrect.', ['attempts_remaining' => $e->attemptsRemaining]),
            };
        }

        // Checked AFTER OTP validation, same deliberate ordering as
        // PublicProposalController::approve() — a wrong OTP against an already-approved change
        // order still reports OTP_INVALID, not this.
        if ($changeOrder->status === 'approved') {
            return $this->error(409, 'CHANGE_ORDER_ALREADY_APPROVED', 'This change order has already been approved.');
        }

        // No dedicated "approver name" column on `approvals` — folded into `comment` with a
        // clear prefix, same convention as PublicProposalController::composeComment().
        $comment = $this->composeComment($request->validated('name'), $request->validated('comment'));

        $body = DB::transaction(function () use ($changeOrder, $challenge, $comment, $request) {
            $approvedAt = now();

            $changeOrder->forceFill(['status' => 'approved', 'approved_at' => $approvedAt])->save();

            $approval = Approval::create([
                'organization_id' => $changeOrder->project->organization_id,
                'project_id' => $changeOrder->project_id,
                'entity_type' => ChangeOrder::ENTITY_TYPE,
                'entity_id' => $changeOrder->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'approved',
                'comment' => $comment,
                'approved_at' => $approvedAt,
                'ip_address' => $request->ip(),
            ]);

            $challenge->forceFill(['verified_at' => $approvedAt])->save();

            return [
                'status' => 'approved',
                'approved_at' => $approvedAt->toJSON(),
                'approval_id' => $approval->id,
            ];
        });

        $this->signedLinkService->markUsed($token);

        // Only a genuinely NEW successful request stores an idempotency record — a replay never
        // reaches here (it returned above), and a failed/409 response is deliberately NOT
        // stored, same convention as PublicProposalController::approve().
        if ($idempotencyKey) {
            IdempotencyKey::create([
                'scope' => $scope,
                'key' => $idempotencyKey,
                'response_status' => 200,
                'response_body' => $body,
            ]);
        }

        return response()->json($body, 200);
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

        if ($changeOrder->status === 'approved') {
            return $this->error(409, 'CHANGE_ORDER_ALREADY_APPROVED', 'This change order has already been approved and can no longer be rejected.');
        }

        $comment = $this->composeComment($request->validated('name'), $request->validated('comment'));

        DB::transaction(function () use ($changeOrder, $comment, $request) {
            $changeOrder->forceFill(['status' => 'rejected'])->save();

            Approval::create([
                'organization_id' => $changeOrder->project->organization_id,
                'project_id' => $changeOrder->project_id,
                'entity_type' => ChangeOrder::ENTITY_TYPE,
                'entity_id' => $changeOrder->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'rejected',
                'comment' => $comment,
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'data' => [
                'status' => 'rejected',
            ],
        ]);
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

    private function composeComment(string $name, ?string $comment): string
    {
        return $comment ? "{$name}: {$comment}" : $name;
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
