<?php

namespace App\Services\Proposals;

use App\Models\IdempotencyKey;
use App\Models\OtpChallenge;
use App\Models\ProposalVersion;
use App\Models\SignedLink;
use App\Support\PublicLinks\SignedLinkService;

/**
 * A client's decision on a sent proposal — approve (with the 6-digit code) or request changes —
 * shared by the two places a client can make it (docs/SIMPLIFICATION_REVIEW.md P3.1):
 *  - the per-document link /p/proposals/{token} (PublicProposalController), still honoured for
 *    proposals sent before the portal became the single link;
 *  - the client portal /p/client-portal/{token}/proposals/{id} (PublicClientPortalDecisionController).
 *
 * Moved verbatim out of PublicProposalController::approve()/requestChanges() so both entry points
 * enforce the same rules in the same order: idempotent replay first, then the OTP issued with the
 * proposal's send link, THEN the already-approved 409 (a wrong code against an approved proposal
 * still reports OTP_INVALID — see OtpChallengeService::verify()), then ProposalApprovalService.
 *
 * Returns [httpStatus, body] so each controller keeps its own response plumbing.
 */
class ProposalClientDecision
{
    public const LINK_PURPOSE = 'proposal_approval';

    public function __construct(
        private readonly OtpChallengeService $otpService,
        private readonly ProposalApprovalService $approvalService,
        private readonly SignedLinkService $signedLinkService,
    ) {}

    /** The approval link issued when this version was sent — its OTP challenge is the one to check. */
    public function sendLinkFor(ProposalVersion $version): ?SignedLink
    {
        return SignedLink::query()
            ->where('purpose', self::LINK_PURPOSE)
            ->where('payload_json->proposal_version_id', $version->id)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function approve(
        ProposalVersion $version,
        ?SignedLink $link,
        string $otp,
        string $name,
        ?string $comment,
        ?string $ipAddress,
        ?string $idempotencyKey,
    ): array {
        $scope = "proposal_approve:{$version->id}";

        if ($idempotencyKey) {
            $stored = IdempotencyKey::query()->where('scope', $scope)->where('key', $idempotencyKey)->first();

            if ($stored) {
                return [$stored->response_status, $stored->response_body];
            }
        }

        $challenge = $link ? OtpChallenge::query()->where('signed_link_id', $link->id)->latest('id')->first() : null;

        if (! $link || ! $challenge) {
            // Data-integrity fallback only — every 'sent' version gets exactly one signed link +
            // one otp_challenge in the same transaction (ProposalSendService::send()).
            return $this->error(404, 'proposal_link_invalid', 'This proposal link is invalid, expired, or has been revoked.');
        }

        try {
            $this->otpService->verify($challenge, $otp);
        } catch (OtpVerificationException $e) {
            return match ($e->reason) {
                'locked' => $this->error(422, 'OTP_LOCKED', 'Too many incorrect attempts. This proposal can no longer be approved from this link.'),
                'expired' => $this->error(422, 'OTP_EXPIRED', 'This verification code has expired.'),
                default => $this->error(422, 'OTP_INVALID', 'The verification code is incorrect.', ['attempts_remaining' => $e->attemptsRemaining]),
            };
        }

        if ($version->status === 'approved') {
            return $this->error(409, 'PROPOSAL_ALREADY_APPROVED', 'This proposal has already been approved.');
        }

        $body = $this->approvalService->approve($version, $this->composeComment($name, $comment), $ipAddress);
        $challenge->forceFill(['verified_at' => $body['approved_at']])->save();
        $this->signedLinkService->markLinkUsed($link);

        // Only a genuinely new success is stored — failures and 409s are not.
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
     * No OTP and no replay store — lower stakes than approval (a comment, not a commercial
     * action). Refuses to send an already-approved proposal back for changes.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function requestChanges(ProposalVersion $version, string $name, ?string $comment, ?string $ipAddress): array
    {
        if ($version->status === 'approved') {
            return $this->error(409, 'PROPOSAL_ALREADY_APPROVED', 'This proposal has already been approved and can no longer be sent back for changes.');
        }

        $this->approvalService->requestChanges($version, $this->composeComment($name, $comment), $ipAddress);

        return [200, ['data' => ['status' => 'changes_requested']]];
    }

    /** No "approver name" column on `approvals` — folded into the comment so it's kept for audit. */
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
