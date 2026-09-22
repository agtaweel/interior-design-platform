<?php

namespace App\Services\ChangeOrders;

use RuntimeException;

/**
 * Thrown by ChangeOrderApplyService::apply() when a business-rule precondition (distinct from
 * the change_order's own status, which the controller already checks before calling in) blocks
 * applying. Today the only case is `CHANGE_ORDER_NO_CONTRACT` — see that service's docblock for
 * the reasoning behind treating a missing contract as a hard failure rather than silently
 * skipping the contract-value update.
 *
 * Named $errorCode, not $code: RuntimeException/Exception already declares a non-readonly
 * $code property (an int, for the standard exception-code convention) — redeclaring it here as
 * a readonly string via constructor promotion is a fatal "cannot redeclare non-readonly
 * property as readonly" error, not a harmless override.
 */
class ChangeOrderApplyException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
