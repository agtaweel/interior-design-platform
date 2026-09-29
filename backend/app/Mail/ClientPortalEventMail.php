<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Platform Readiness Review finding #08: a client who approves/rejects/requests changes on a
 * proposal or change order via the public portal (PublicProposalController::approve()/
 * requestChanges(), PublicChangeOrderController::approve()/reject()) previously got no
 * confirmation that their action was received — only staff got an in-app NotificationService
 * ping. This mailable closes that loop with a short "we got it" confirmation, one per outcome,
 * reusing the same `documentLabel` ('proposal' | 'change order') parameterization OtpCodeMail
 * already established rather than writing four near-identical Mailables.
 *
 * Same "no queue worker running" reasoning as OtpCodeMail/PasswordResetMail — sent synchronously,
 * not ShouldQueue.
 */
class ClientPortalEventMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $documentLabel,
        public readonly string $documentNumber,
        public readonly string $projectName,
        public readonly string $organizationName,
        public readonly string $outcome,
    ) {}

    public function build(): self
    {
        return $this->subject($this->subjectLine())->view('emails.client-portal-event');
    }

    private function subjectLine(): string
    {
        return match ($this->outcome) {
            'approved' => ucfirst($this->documentLabel)." approved — {$this->documentNumber}",
            'rejected' => ucfirst($this->documentLabel)." rejected — {$this->documentNumber}",
            'changes_requested' => "Change request received — {$this->documentNumber}",
        };
    }
}
