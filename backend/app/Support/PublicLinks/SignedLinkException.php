<?php

namespace App\Support\PublicLinks;

use RuntimeException;

/**
 * Thrown by SignedLinkService::verify() when a token is invalid, expired, or revoked.
 * $reason is one of: "invalid", "expired", "revoked" — callers building the eventual
 * /public/... controllers can map this to a 404 (invalid/expired, to avoid confirming a
 * token ever existed) or a more specific message as appropriate for that endpoint.
 */
class SignedLinkException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Signed link is {$reason}.");
    }
}
