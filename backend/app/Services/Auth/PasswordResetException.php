<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Thrown by PasswordResetService::reset(). `reason` is 'invalid' (unknown/already-used token,
 * or the token doesn't match) or 'expired' — the controller renders both as the same generic
 * 422 message (see AuthController::resetPassword()) rather than distinguishing them to the
 * caller, same "don't help an attacker distinguish a guessed token that once existed from one
 * that never did" posture as SignedLinkException.
 */
class PasswordResetException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Password reset token is {$reason}.");
    }
}
