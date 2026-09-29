<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Platform Readiness Review finding #04 — see PasswordResetService's docblock for why this is a
 * small custom implementation rather than Laravel's built-in Password broker. Not ShouldQueue,
 * same reasoning as OtpCodeMail (no queue worker runs in this environment).
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $resetUrl,
        public readonly int $expiryMinutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Reset your password')
            ->view('emails.password-reset');
    }
}
