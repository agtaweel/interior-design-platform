<?php

namespace App\Services\ChangeOrders;

use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Draft-lifecycle operations for change orders (PROJECT_CONTEXT.md Sprint 7 "Create" step and
 * the item-editing half of PATCH) — mirrors ProposalVersionService's role for proposal drafts.
 * Sending (draft -> sent, signed link + OTP issuance) is a separate concern, see
 * ChangeOrderSendService; applying (approved -> applied, BOQ/contract mutation) is likewise
 * separate, see ChangeOrderApplyService.
 *
 * Every public method wraps its work in DB::transaction() per PROJECT_CONTEXT.md's NFR that
 * commercial mutations (creating/editing a change order's frozen-while-non-draft item deltas)
 * are transactional. All money math uses bcmath — never float — per the project's money rules.
 */
class ChangeOrderService
{
    /**
     * Bounds the `number` conflict-retry loop (see generateNumber()'s docblock), mirroring
     * ContractService::MAX_CONTRACT_NO_ATTEMPTS exactly.
     */
    private const MAX_NUMBER_ATTEMPTS = 5;

    /**
     * POST /projects/{id}/change-orders. Creates the draft row, builds its items from the
     * validated payload (computing each line_delta server-side), then sums those into
     * price_delta. `number` generation retries on a rare concurrent-create collision, same
     * pattern as ContractService::fromProposal()'s contract_no loop (inner DB::transaction()
     * uses a real SAVEPOINT under Postgres, so a caught collision only unwinds to the
     * savepoint, not the whole request).
     */
    public function createDraft(Project $project, array $data, ?int $requestedBy): ChangeOrder
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($project, $data, $requestedBy) {
                    $changeOrder = ChangeOrder::create([
                        'project_id' => $project->id,
                        'number' => $this->generateNumber(),
                        'status' => 'draft',
                        'reason' => $data['reason'],
                        'timeline_delta_days' => $data['timeline_delta_days'] ?? null,
                        'requested_by' => $requestedBy,
                    ]);

                    $this->buildItems($changeOrder, $project, $data['items']);
                    $this->recomputePriceDelta($changeOrder);

                    return $changeOrder->fresh(['items']);
                });
            } catch (QueryException $e) {
                if ($this->isNumberConflict($e) && $attempts < self::MAX_NUMBER_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * PATCH /change-orders/{id}. Only ever called by the controller after it has verified
     * status == 'draft' — this method does not re-check that itself, matching
     * ProposalVersionService::resnapshot()'s identical convention.
     *
     * `items`, when present in $data, wholesale-replaces the existing set (delete-then-rebuild,
     * same rationale as ProposalVersionService::snapshotItemsFromBoq() — change order items
     * have no independent identity a client needs preserved across an edit, and a full replace
     * is simplest/safest against drift). price_delta is only recomputed when items actually
     * changed.
     */
    public function updateDraft(ChangeOrder $changeOrder, array $data): ChangeOrder
    {
        return DB::transaction(function () use ($changeOrder, $data) {
            $attrs = [];

            if (array_key_exists('reason', $data)) {
                $attrs['reason'] = $data['reason'];
            }

            if (array_key_exists('timeline_delta_days', $data)) {
                $attrs['timeline_delta_days'] = $data['timeline_delta_days'];
            }

            if ($attrs !== []) {
                $changeOrder->update($attrs);
            }

            if (array_key_exists('items', $data)) {
                $changeOrder->items()->delete();
                $this->buildItems($changeOrder, $changeOrder->project, $data['items']);
                $this->recomputePriceDelta($changeOrder);
            }

            return $changeOrder->fresh(['items']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsInput
     */
    private function buildItems(ChangeOrder $changeOrder, Project $project, array $itemsInput): void
    {
        foreach ($itemsInput as $itemInput) {
            $this->buildItem($changeOrder, $project, $itemInput);
        }
    }

    /**
     * Builds one change_order_item row, resolving old_unit_price and computing line_delta per
     * PROJECT_CONTEXT.md's exact formulas (also documented on ChangeOrderItem's model docblock):
     *   add    -> quantity * new_unit_price (positive)
     *   remove -> -(quantity * old_unit_price) (negative)
     *   modify -> quantity * (new_unit_price - old_unit_price) (sign follows price direction)
     *
     * boq_item_id existence/ownership (belongs to this project, not archived) is already
     * enforced by StoreChangeOrderRequest/UpdateChangeOrderRequest's Rule::exists — fetched here
     * un-checked for that reason, only to read its client_unit_price for the old_unit_price
     * fallback below.
     */
    private function buildItem(ChangeOrder $changeOrder, Project $project, array $itemInput): ChangeOrderItem
    {
        $action = $itemInput['action'];
        $quantity = (string) $itemInput['quantity'];

        $boqItem = in_array($action, ['remove', 'modify'], true)
            ? BoqItem::query()->where('project_id', $project->id)->find($itemInput['boq_item_id'])
            : null;

        $oldUnitPrice = null;
        $newUnitPrice = null;

        if ($action === 'add') {
            $newUnitPrice = (string) $itemInput['new_unit_price'];
        } elseif ($action === 'remove') {
            $oldUnitPrice = $this->resolveOldUnitPrice($boqItem);
        } else { // modify
            $oldUnitPrice = $this->resolveOldUnitPrice($boqItem);
            $newUnitPrice = (string) $itemInput['new_unit_price'];
        }

        return ChangeOrderItem::create([
            'change_order_id' => $changeOrder->id,
            'action' => $action,
            'boq_item_id' => $boqItem?->id,
            'description' => $itemInput['description'],
            'quantity' => $quantity,
            'unit' => $itemInput['unit'],
            'old_unit_price' => $oldUnitPrice,
            'new_unit_price' => $newUnitPrice,
            'line_delta' => $this->computeLineDelta($action, $quantity, $oldUnitPrice, $newUnitPrice),
        ]);
    }

    /**
     * "look it up from the referenced boq_item's current client_unit_price rather than trusting
     * client input, since that's the authoritative 'old' price" (PROJECT_CONTEXT.md verbatim).
     *
     * `old_unit_price` is commercially load-bearing: it feeds `line_delta`, which sums into
     * `price_delta`, which is added directly to `contracts.contract_value` on apply — bypassing
     * the normal PATCH-based immutability guard by design (see ChangeOrderApplyService). Any
     * client-supplied `old_unit_price` is therefore ALWAYS ignored for remove/modify, even if
     * present in the request payload — accepting a client-trusted value here would let an
     * internal caller (accidentally or not) fabricate the commercial delta a client approves
     * and that later lands on the contract. Always re-derive from the live BOQ item at write
     * time (never a stale client-cached figure).
     */
    private function resolveOldUnitPrice(?BoqItem $boqItem): string
    {
        return (string) ($boqItem->client_unit_price ?? '0.00');
    }

    private function computeLineDelta(string $action, string $quantity, ?string $oldUnitPrice, ?string $newUnitPrice): string
    {
        return match ($action) {
            'add' => bcmul($quantity, $newUnitPrice, 2),
            'remove' => bcmul('-1', bcmul($quantity, $oldUnitPrice, 2), 2),
            'modify' => bcmul($quantity, bcsub($newUnitPrice, $oldUnitPrice, 2), 2),
        };
    }

    /**
     * Sums items' line_delta via bcmath in PHP (fetch-then-fold), not a DB-level SUM() — keeps
     * the accumulation decimal-exact and consistent with the rest of this codebase's "bcmath at
     * the write path" convention (see BoqItem::directCost()/clientTotal()).
     */
    private function recomputePriceDelta(ChangeOrder $changeOrder): void
    {
        $total = '0.00';

        foreach ($changeOrder->items()->get() as $item) {
            $total = bcadd($total, (string) $item->line_delta, 2);
        }

        $changeOrder->forceFill(['price_delta' => $total])->save();
    }

    /**
     * Best-effort sequential code (e.g. "CO-00007"), mirroring
     * ContractService::generateContractNo()'s "CTR-00001" pattern exactly — change_orders.number
     * is globally unique (no organization_id to compound against, same rationale as
     * contracts.contract_no).
     */
    private function generateNumber(): string
    {
        $next = ChangeOrder::query()->count() + 1;

        return 'CO-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Mirrors ContractService::isContractNoConflict()'s narrowing exactly — only treats a
     * unique-violation mentioning `number` specifically as retryable, so a genuine unrelated
     * constraint violation bubbles up as a real error instead of looping.
     */
    private function isNumberConflict(QueryException $e): bool
    {
        $isUniqueViolation = $e->getCode() === '23505' || str_contains(strtolower($e->getMessage()), 'unique constraint');

        return $isUniqueViolation && str_contains(strtolower($e->getMessage()), 'number');
    }
}
