<?php

namespace App\Services\ChangeOrders;

use App\Models\Approval;
use App\Models\ChangeOrder;
use App\Models\IdempotencyKey;
use App\Models\OtpChallenge;
use App\Models\SignedLink;
use App\Services\Notifications\ClientPortalEventMailer;
use App\Services\Notifications\NotificationService;
use App\Services\Proposals\OtpChallengeService;
use App\Services\Proposals\OtpVerificationException;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Support\Facades\DB;

/**
 * A client's decision on a sent change order — approve (with the 6-digit code) or reject —
 * shared by the per-document link /p/change-orders/{token} (PublicChangeOrderController) and the
 * client portal (PublicClientPortalDecisionController), docs/SIMPLIFICATION_REVIEW.md P3.1.
 *
 * Moved verbatim out of PublicChangeOrderController::approve()/reject(): idempotent replay first,
 * then the OTP issued with the change order's send link, THEN the already-approved 409, then one
 * transaction for status + Approval row + optional auto-apply (ChangeOrderAutoApplier, P2.4) +
 * staff notification; client mail after commit.
 *
 * Returns [httpStatus, body] so each controller keeps its own response plumbing.
 */
class ChangeOrderClientDecision
{
    public function __construct(
        private readonly OtpChallengeService $otpService,
        private readonly NotificationService $notificationService,
        private readonly ClientPortalEventMailer $clientMailer,
        private readonly ChangeOrderAutoApplier $autoApplier,
        private readonly SignedLinkService $signedLinkService,
    ) {}

    /** The approval link issued when this change order was sent — its OTP challenge is the one to check. */
    public function sendLinkFor(ChangeOrder $changeOrder): ?SignedLink
    {
        return SignedLink::query()
            ->where('purpose', ChangeOrderSendService::PURPOSE)
            ->where('payload_json->change_order_id', $changeOrder->id)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function approve(
        ChangeOrder $changeOrder,
        ?SignedLink $link,
        string $otp,
        string $name,
        ?string $comment,
        ?string $ipAddress,
        ?string $idempotencyKey,
    ): array {
        $scope = "change_order_approve:{$changeOrder->id}";

        if ($idempotencyKey) {
            $stored = IdempotencyKey::query()->where('scope', $scope)->where('key', $idempotencyKey)->first();

            if ($stored) {
                return [$stored->response_status, $stored->response_body];
            }
        }

        $challenge = $link ? OtpChallenge::query()->where('signed_link_id', $link->id)->latest('id')->first() : null;

        if (! $link || ! $challenge) {
            // Data-integrity fallback only — every 'sent' change order gets exactly one signed
            // link + one otp_challenge in the same transaction (ChangeOrderSendService::send()).
            return $this->error(404, 'change_order_link_invalid', 'This change order link is invalid, expired, or has been revoked.');
        }

        try {
            $this->otpService->verify($challenge, $otp);
        } catch (OtpVerificationException $e) {
            return match ($e->reason) {
                'locked' => $this->error(422, 'OTP_LOCKED', 'Too many incorrect attempts. This change order can no longer be approved from this link.'),
                'expired' => $this->error(422, 'OTP_EXPIRED', 'This verification code has expired.'),
                default => $this->error(422, 'OTP_INVALID', 'The verification code is incorrect.', ['attempts_remaining' => $e->attemptsRemaining]),
            };
        }

        // After OTP validation, same deliberate ordering as the proposal side.
        if ($changeOrder->status === 'approved' || $changeOrder->status === 'applied') {
            return $this->error(409, 'CHANGE_ORDER_ALREADY_APPROVED', 'This change order has already been approved.');
        }

        $changeOrder->loadMissing('project');
        $commentText = $this->composeComment($name, $comment);

        $body = DB::transaction(function () use ($changeOrder, $challenge, $commentText, $ipAddress) {
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
                'comment' => $commentText,
                'approved_at' => $approvedAt,
                'ip_address' => $ipAddress,
            ]);

            $challenge->forceFill(['verified_at' => $approvedAt])->save();

            // Opt-in per organization (P2.4): apply to the BOQ + contract in this same step.
            // Never fails the approval — see ChangeOrderAutoApplier.
            $applied = $this->autoApplier->attempt($changeOrder);

            $this->notificationService->notify($changeOrder->project, 'change_order_approved', [
                'project_id' => $changeOrder->project_id,
                'project_name' => $changeOrder->project->name,
                'change_order_id' => $changeOrder->id,
                'number' => $changeOrder->number,
                'applied' => $applied,
                'summary' => $applied
                    ? sprintf('Change Order %s for %s was approved by the client and applied to the BOQ and contract automatically.', $changeOrder->number, $changeOrder->project->name)
                    : sprintf('Change Order %s for %s was approved by the client.', $changeOrder->number, $changeOrder->project->name),
            ]);

            return [
                'status' => 'approved',
                'approved_at' => $approvedAt->toJSON(),
                'approval_id' => $approval->id,
                'applied' => $applied,
            ];
        });

        $this->clientMailer->changeOrderDecided($changeOrder, 'approved');
        $this->signedLinkService->markLinkUsed($link);

        if ($idempotencyKey) {
            IdempotencyKey::create([
                'scope' => $scope,
                'key' => $idempotencyKey,
                'response_status' => 200,
                'response_body' => $body,
            ]);
        }

        return [200, $body];
    }

    /**
     * No OTP and no replay store — lower stakes than approval. A rejected change order is
     * terminal (staff create a new one); refuses to reject one that's already approved.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function reject(ChangeOrder $changeOrder, string $name, ?string $comment, ?string $ipAddress): array
    {
        if ($changeOrder->status === 'approved' || $changeOrder->status === 'applied') {
            return $this->error(409, 'CHANGE_ORDER_ALREADY_APPROVED', 'This change order has already been approved and can no longer be rejected.');
        }

        $changeOrder->loadMissing('project');
        $commentText = $this->composeComment($name, $comment);

        DB::transaction(function () use ($changeOrder, $commentText, $ipAddress) {
            $changeOrder->forceFill(['status' => 'rejected'])->save();

            Approval::create([
                'organization_id' => $changeOrder->project->organization_id,
                'project_id' => $changeOrder->project_id,
                'entity_type' => ChangeOrder::ENTITY_TYPE,
                'entity_id' => $changeOrder->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'rejected',
                'comment' => $commentText,
                'ip_address' => $ipAddress,
            ]);

            $this->notificationService->notify($changeOrder->project, 'change_order_rejected', [
                'project_id' => $changeOrder->project_id,
                'project_name' => $changeOrder->project->name,
                'change_order_id' => $changeOrder->id,
                'number' => $changeOrder->number,
                'summary' => sprintf('Change Order %s for %s was rejected by the client.', $changeOrder->number, $changeOrder->project->name),
            ]);
        });

        $this->clientMailer->changeOrderDecided($changeOrder, 'rejected');

        return [200, ['data' => ['status' => 'rejected']]];
    }

    private function composeComment(string $name, ?string $comment): string
    {
        return $comment ? "{$name}: {$comment}" : $name;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function error(int $status, string $code, string $message, array $details = []): array
    {
        return [$status, ['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]]];
    }
}
