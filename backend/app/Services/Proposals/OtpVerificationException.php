<?php

namespace App\Services\Proposals;

use RuntimeException;

/**
 * Thrown by OtpChallengeService::verify(). $reason is one of:
 *   - "invalid": code did not match the stored hash (attempts already incremented by the time
 *     this is thrown).
 *   - "expired": otp_challenges.expires_at has passed.
 *   - "locked": attempts already reached the cap (App\Services\Proposals\OtpChallengeService::MAX_ATTEMPTS)
 *     on a PRIOR request — this request didn't even get to check the code.
 *
 * $attemptsRemaining is only meaningful for "invalid" (null otherwise) — lets the controller
 * tell the client how many tries are left before lockout, per PROJECT_CONTEXT.md's "return a
 * clear locked-out error rather than allowing brute force".
 */
class OtpVerificationException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $attemptsRemaining = null)
    {
        parent::__construct("OTP is {$reason}.");
    }
}
