<?php

namespace App\Services\Proposals;

use App\Models\Approval;
use App\Models\ProposalVersion;
use App\Services\Notifications\ClientPortalEventMailer;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * BRD v4 "Client Marketplace" — extracted from PublicProposalController::approve()/
 * requestChanges()'s transaction bodies so the anonymous OTP-approval flow and the new
 * logged-in marketplace-client flow (ClientDealController) share one implementation of "what
 * approving/rejecting a proposal actually does" (status flip, Approval row, notify, mail).
 * What differs between the two callers — token/OTP verification, the already-approved 409
 * ordering relative to OTP checks — stays in each controller; this service only owns the part
 * that's identical either way.
 */
class ProposalApprovalService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ClientPortalEventMailer $clientMailer,
    ) {}

    /**
     * @return array{status: string, approved_at: string, approval_id: int, contract_conversion_available: bool}
     */
    public function approve(ProposalVersion $version, string $comment, ?string $ipAddress): array
    {
        $body = DB::transaction(function () use ($version, $comment, $ipAddress) {
            $approvedAt = now();

            $version->forceFill(['status' => 'approved', 'approved_at' => $approvedAt])->save();

            $approval = Approval::create([
                'organization_id' => $version->project->organization_id,
                'project_id' => $version->project_id,
                'entity_type' => ProposalVersion::ENTITY_TYPE,
                'entity_id' => $version->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'approved',
                'comment' => $comment,
                'approved_at' => $approvedAt,
                'ip_address' => $ipAddress,
            ]);

            $this->notificationService->notify($version->project, 'proposal_approved', [
                'project_id' => $version->project_id,
                'project_name' => $version->project->name,
                'proposal_version_id' => $version->id,
                'version_no' => $version->version_no,
                'summary' => sprintf('Proposal v%d for %s was approved by the client.', $version->version_no, $version->project->name),
            ]);

            return [
                'status' => 'approved',
                'approved_at' => $approvedAt->toJSON(),
                'approval_id' => $approval->id,
                'contract_conversion_available' => true,
            ];
        });

        $this->clientMailer->proposalDecided($version, 'approved');

        return $body;
    }

    public function requestChanges(ProposalVersion $version, string $comment, ?string $ipAddress): void
    {
        DB::transaction(function () use ($version, $comment, $ipAddress) {
            $version->forceFill(['status' => 'changes_requested'])->save();

            Approval::create([
                'organization_id' => $version->project->organization_id,
                'project_id' => $version->project_id,
                'entity_type' => ProposalVersion::ENTITY_TYPE,
                'entity_id' => $version->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'changes_requested',
                'comment' => $comment,
                'ip_address' => $ipAddress,
            ]);

            $this->notificationService->notify($version->project, 'proposal_changes_requested', [
                'project_id' => $version->project_id,
                'project_name' => $version->project->name,
                'proposal_version_id' => $version->id,
                'version_no' => $version->version_no,
                'summary' => sprintf('Client requested changes to Proposal v%d for %s.', $version->version_no, $version->project->name),
            ]);
        });

        $this->clientMailer->proposalDecided($version, 'changes_requested');
    }
}
