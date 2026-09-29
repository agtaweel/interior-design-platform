<?php

namespace App\Services\Notifications;

use App\Mail\ClientPortalEventMail;
use App\Models\ChangeOrder;
use App\Models\ProposalVersion;
use Illuminate\Support\Facades\Mail;

/**
 * Platform Readiness Review finding #08. Shared by PublicProposalController and
 * PublicChangeOrderController — both fire this AFTER their DB::transaction() commits, same
 * post-commit-side-effect convention as ProposalSendService::deliverOtpByEmail(). Best-effort: a
 * client with no email on file, or a failed send, never blocks or fails the approve/reject
 * request itself (the mutation already happened).
 */
class ClientPortalEventMailer
{
    public function proposalDecided(ProposalVersion $version, string $outcome): void
    {
        $version->loadMissing(['project.client', 'project.organization']);

        $this->send($version->project->client?->name, $version->project->client?->email, [
            'documentLabel' => 'proposal',
            'documentNumber' => "v{$version->version_no}",
            'projectName' => $version->project->name,
            'organizationName' => $version->project->organization->name,
            'outcome' => $outcome,
        ]);
    }

    public function changeOrderDecided(ChangeOrder $changeOrder, string $outcome): void
    {
        $changeOrder->loadMissing(['project.client', 'project.organization']);

        $this->send($changeOrder->project->client?->name, $changeOrder->project->client?->email, [
            'documentLabel' => 'change order',
            'documentNumber' => $changeOrder->number,
            'projectName' => $changeOrder->project->name,
            'organizationName' => $changeOrder->project->organization->name,
            'outcome' => $outcome,
        ]);
    }

    /**
     * @param array{documentLabel: string, documentNumber: string, projectName: string, organizationName: string, outcome: string} $data
     */
    private function send(?string $clientName, ?string $clientEmail, array $data): void
    {
        if (! $clientEmail) {
            return;
        }

        try {
            Mail::to($clientEmail)->send(new ClientPortalEventMail(
                recipientName: $clientName ?? 'there',
                documentLabel: $data['documentLabel'],
                documentNumber: $data['documentNumber'],
                projectName: $data['projectName'],
                organizationName: $data['organizationName'],
                outcome: $data['outcome'],
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
