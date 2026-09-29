<?php

namespace App\Services\ChangeOrders;

use App\Mail\OtpCodeMail;
use App\Models\ChangeOrder;
use App\Models\SignedLink;
use App\Services\Proposals\OtpChallengeService;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * POST /change-orders/{id}/send (PROJECT_CONTEXT.md Sprint 7). Only ever called by
 * ChangeOrderController::send() after it has verified status == 'draft' — this class does not
 * re-check that itself, matching ProposalSendService's identical convention.
 *
 * Mirrors ProposalSendService structurally, with one simplification: change_orders has no
 * snapshot_json column (unlike proposal_versions) — the immutability-at-sent boundary here is
 * enforced purely by the PATCH endpoint's draft-only guard (ChangeOrderController::update()),
 * so there is nothing to freeze into a separate blob; the live change_order_items rows ARE the
 * frozen record once status leaves 'draft'. REUSES OtpChallengeService/SignedLinkService
 * verbatim, per PROJECT_CONTEXT.md's explicit instruction not to reimplement hashing/expiry/
 * attempt-cap logic for this second consumer.
 *
 * Same link-expiry-vs-OTP-expiry judgment call as ProposalSendService (30-day link, 72h OTP via
 * OtpChallengeService::EXPIRY_HOURS) — see that class's docblock for the full reasoning, which
 * applies identically here.
 */
class ChangeOrderSendService
{
    private const LINK_EXPIRY_DAYS = 30;

    public const PURPOSE = 'change_order_approval';

    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly OtpChallengeService $otpService,
    ) {}

    /**
     * @return array{change_order: ChangeOrder, token: string, otp_code: string, public_url: string}
     */
    public function send(ChangeOrder $changeOrder): array
    {
        $result = DB::transaction(function () use ($changeOrder) {
            $sentAt = now();

            $changeOrder->forceFill(['status' => 'sent', 'sent_at' => $sentAt])->save();

            $token = $this->signedLinkService->issue(
                purpose: self::PURPOSE,
                payload: ['change_order_id' => $changeOrder->id],
                expiresAt: $sentAt->copy()->addDays(self::LINK_EXPIRY_DAYS),
                createdBy: $changeOrder->requested_by,
            );

            $link = SignedLink::query()->where('token_hash', hash('sha256', $token))->firstOrFail();

            $otp = $this->otpService->issue($link);

            return [
                'change_order' => $changeOrder->fresh(['items']),
                'token' => $token,
                'otp_code' => $otp['code'],
                'public_url' => sprintf('%s/p/change-orders/%s', config('app.frontend_url'), $token),
            ];
        });

        // Post-commit, same reasoning as ProposalSendService::deliverOtpByEmail()'s docblock.
        $this->deliverOtpByEmail($result['change_order'], $result['otp_code'], $result['public_url']);

        return $result;
    }

    /**
     * Platform Readiness Review finding #03 — see ProposalSendService::deliverOtpByEmail()'s
     * identical rationale. Best-effort and additive: a missing client email, or a mail transport
     * failure, must never fail send() itself.
     */
    private function deliverOtpByEmail(ChangeOrder $changeOrder, string $code, string $publicUrl): void
    {
        $changeOrder->loadMissing(['project.client', 'project.organization']);
        $client = $changeOrder->project->client;

        if (! $client || ! $client->email) {
            return;
        }

        try {
            Mail::to($client->email)->send(new OtpCodeMail(
                recipientName: $client->name,
                code: $code,
                documentLabel: 'change order',
                projectName: $changeOrder->project->name,
                organizationName: $changeOrder->project->organization->name,
                publicUrl: $publicUrl,
                expiryHours: OtpChallengeService::EXPIRY_HOURS,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
