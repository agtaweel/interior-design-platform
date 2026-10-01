<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * BRD v4 "Client Marketplace" — sent instead of OtpCodeMail when a sent proposal's client is
 * marketplace-linked (Client::client_user_id is set): that client approves from their own
 * logged-in dashboard (ClientDealController, no OTP — see
 * config('fitout.marketplace_deal_requires_otp')'s docblock), so an OTP code in the email would
 * be both unnecessary and misleading. This just points them at their dashboard instead.
 *
 * Not ShouldQueue, same reasoning as OtpCodeMail — no queue worker runs in this environment.
 */
class MarketplaceDealMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $projectName,
        public readonly string $organizationName,
        public readonly string $dashboardUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("{$this->organizationName} sent you a deal")
            ->view('emails.marketplace-deal');
    }
}
