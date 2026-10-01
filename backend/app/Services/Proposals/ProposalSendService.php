<?php

namespace App\Services\Proposals;

use App\Mail\MarketplaceDealMail;
use App\Mail\OtpCodeMail;
use App\Models\ProposalVersion;
use App\Models\SignedLink;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * POST /proposals/{id}/send (PROJECT_CONTEXT.md Sprint 4). Only ever called by
 * ProposalVersionController::send() after it has verified status == 'draft' — this class does
 * not re-check that itself, matching ProposalVersionService::resnapshot()'s convention.
 *
 * ## Signed-link expiry vs OTP expiry (documented judgment call)
 *
 * PROJECT_CONTEXT.md specifies the OTP's expiry (~72h, see OtpChallengeService) but leaves the
 * signed link's own expiry unspecified beyond "one signed link per sent version". We give the
 * link a much longer expiry (30 days) than the OTP (72h): GET /public/proposals/{token} (just
 * viewing) should keep working long after the OTP has lapsed — a client re-opening a WhatsApp
 * link a week later to re-read the scope/terms is a normal flow, and gating VIEWING behind the
 * same short OTP window would be needlessly restrictive. Only the approve action needs the
 * short-lived secret. Known limitation: there is no "resend/regenerate OTP" endpoint this
 * sprint (sending only transitions draft -> sent once, per the immutability rule — you cannot
 * re-send an already-sent version), so once the 72h OTP window lapses, that version can no
 * longer be approved even though its link still resolves for viewing; the documented recourse
 * is what the immutability rule already prescribes for any post-send change: create a new
 * draft version and send that instead.
 */
class ProposalSendService
{
    private const LINK_EXPIRY_DAYS = 30;

    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly OtpChallengeService $otpService,
        private readonly ProposalPresenter $presenter,
    ) {}

    /**
     * @return array{version: ProposalVersion, token: string, otp_code: string, public_url: string}
     */
    public function send(ProposalVersion $version): array
    {
        $result = DB::transaction(function () use ($version) {
            $sentAt = now();

            // Set status/sent_at on the in-memory model BEFORE building the payload (not
            // saved yet) — ProposalPresenter::buildPayload() reads $version->status/sent_at
            // directly off the model, so if we built the snapshot first (status still
            // 'draft' at that point) the frozen snapshot_json would permanently record
            // status=draft/sent_at=null for a version that's actually sent. This ordering
            // matters even though ProposalPresenter::present() also overlays LIVE status/
            // sent_at/approved_at on top of whatever's in snapshot_json for public/PDF
            // display (see that method) — GET /proposals/{id}'s internal view still returns
            // snapshot_json verbatim, so it should record a sensible value too.
            $version->forceFill(['status' => 'sent', 'sent_at' => $sentAt]);

            $snapshot = $this->presenter->buildPayload($version);

            $version->forceFill(['snapshot_json' => $snapshot])->save();

            $token = $this->signedLinkService->issue(
                purpose: 'proposal_approval',
                payload: ['proposal_version_id' => $version->id],
                expiresAt: $sentAt->copy()->addDays(self::LINK_EXPIRY_DAYS),
                createdBy: $version->created_by,
            );

            $link = SignedLink::query()->where('token_hash', hash('sha256', $token))->firstOrFail();

            $otp = $this->otpService->issue($link);

            $publicUrl = sprintf('%s/p/proposals/%s', config('app.frontend_url'), $token);

            return [
                'version' => $version->fresh(['items']),
                'token' => $token,
                'otp_code' => $otp['code'],
                'public_url' => $publicUrl,
            ];
        });

        // Fired after the transaction commits, not inside it — same "a notification is not
        // itself a commercial mutation that needs to share the parent action's atomicity" reasoning
        // ContractController::fromProposal() already established for its own post-commit
        // notification call.
        $this->deliverOtpByEmail($result['version'], $result['otp_code'], $result['public_url']);

        return $result;
    }

    /**
     * Platform Readiness Review finding #03: automates what staff previously had to do by hand
     * (copy the OTP + link out of this method's return value and relay both to the client over
     * WhatsApp/phone). Best-effort and additive — a missing client email, or the mail transport
     * being unavailable, must never fail send() itself; staff can always fall back to the manual
     * relay this always supported (the return value above is unchanged).
     *
     * BRD v4 "Client Marketplace": a marketplace-linked client (Client::client_user_id set)
     * gets MarketplaceDealMail instead — an OTP code would be meaningless noise for a client who
     * approves from their own logged-in dashboard, never through this anonymous link.
     */
    private function deliverOtpByEmail(ProposalVersion $version, string $code, string $publicUrl): void
    {
        $version->loadMissing(['project.client', 'project.organization']);
        $client = $version->project->client;

        if (! $client || ! $client->email) {
            return;
        }

        try {
            if ($client->client_user_id) {
                Mail::to($client->email)->send(new MarketplaceDealMail(
                    recipientName: $client->name,
                    projectName: $version->project->name,
                    organizationName: $version->project->organization->name,
                    dashboardUrl: sprintf('%s/client/deals/%d', config('app.frontend_url'), $version->id),
                ));

                return;
            }

            Mail::to($client->email)->send(new OtpCodeMail(
                recipientName: $client->name,
                code: $code,
                documentLabel: 'proposal',
                projectName: $version->project->name,
                organizationName: $version->project->organization->name,
                publicUrl: $publicUrl,
                expiryHours: OtpChallengeService::EXPIRY_HOURS,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
