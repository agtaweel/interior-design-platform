<?php

namespace App\Services\ChangeOrders;

use App\Models\ChangeOrder;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * docs/SIMPLIFICATION_REVIEW.md P2.4 — when an organization opts in
 * (organizations.auto_apply_change_orders), a client's approval of a change order also applies
 * it to the BOQ and contract, in the same transaction as the approval, instead of waiting for
 * staff to press "Apply to Items & Contract".
 *
 * Called from inside PublicChangeOrderController::approve()'s transaction. It never makes the
 * approval itself fail: it only attempts the apply when it can succeed (setting on, a contract
 * exists, someone to attribute it to), and runs it in its own savepoint — if anything still goes
 * wrong, that savepoint rolls back, the error is reported, and the change order simply stays
 * "approved" for manual apply, exactly as with the setting off.
 *
 * Attribution: the public approval route has no authenticated user, so the apply is recorded as
 * performed by the staff member who created the change order (`requested_by`) — they initiated
 * it, and the client's approval is separately recorded on `approvals`. With no such user (deleted
 * account), it is left for manual apply rather than attributed to someone arbitrary.
 */
class ChangeOrderAutoApplier
{
    public function __construct(private readonly ChangeOrderApplyService $applyService) {}

    /** @return bool whether the change order was applied */
    public function attempt(ChangeOrder $changeOrder): bool
    {
        $project = $changeOrder->project;

        if (! $project?->organization?->auto_apply_change_orders || ! $changeOrder->requested_by) {
            return false;
        }

        if (! Contract::query()->where('project_id', $project->id)->exists()) {
            return false;
        }

        try {
            DB::transaction(fn () => $this->applyService->apply($changeOrder, actingUserId: $changeOrder->requested_by));

            return true;
        } catch (Throwable $e) {
            report($e);
            $changeOrder->refresh();

            return false;
        }
    }
}
