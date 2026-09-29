<?php

namespace App\Services\Closeout;

use RuntimeException;

/**
 * Thrown by ProjectCloseoutService when a requested state transition doesn't apply to the
 * project's CURRENT financial_status — e.g. closing a project that isn't yet READY_FOR_CLOSE.
 * The controller renders these as 409 (a state conflict, not invalid input — same "valid
 * reference, wrong current state" reasoning as ChangeOrderApplyException).
 */
class CloseoutStateException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
