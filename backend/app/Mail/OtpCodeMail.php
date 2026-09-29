<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Platform Readiness Review finding #03: the OTP that gates approving a sent proposal/change
 * order was previously only ever returned in the authenticated send() API response — staff had
 * to manually copy it (alongside the public link) and relay both to the client over WhatsApp/
 * phone themselves. This mailable automates that distribution: when the client has an email on
 * file, ProposalSendService/ChangeOrderSendService send them the code + link directly.
 *
 * The send() response still returns `otp_code`/`public_url` too (no existing behavior removed —
 * staff retain manual-relay as a fallback for clients with no email on file, or if a mail send
 * fails); this mailable is an additive, automated first channel, not a replacement for the
 * existing one.
 *
 * Deliberately NOT ShouldQueue — this dev/staging environment has no queue worker process
 * running (QUEUE_CONNECTION=database with nothing consuming it), so a queued mail would just
 * sit in the `jobs` table forever. Sent synchronously instead, matching how every other
 * request-response side effect in this codebase already behaves (e.g. NotificationService).
 */
class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $code,
        public readonly string $documentLabel,
        public readonly string $projectName,
        public readonly string $organizationName,
        public readonly string $publicUrl,
        public readonly int $expiryHours,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("Your verification code for {$this->organizationName}")
            ->view('emails.otp-code');
    }
}
