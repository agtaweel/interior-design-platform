<?php

namespace App\Services\ChangeOrders;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * POST /change-orders/{id}/apply (PROJECT_CONTEXT.md Sprint 7 "Apply" step) — the ONE
 * controlled, audited exception to Sprint 5's contract immutability rule (contract_value is
 * otherwise permanently locked, see UpdateContractRequest's `prohibited` rules). This is a
 * dedicated internal service method, NOT a backdoor through UpdateContractRequest/the generic
 * PATCH /contracts/{id} route — that guard exists specifically to stop contract_value being set
 * via ad-hoc client input; this method sets it directly, from the change order's own
 * already-computed, already-client-approved price_delta.
 *
 * The controller has already verified change_order.status == 'approved' before calling in —
 * this class does not re-check that itself, matching every other *ApplyService/*SendService
 * convention in this codebase.
 *
 * Everything below runs in ONE DB::transaction() — PROJECT_CONTEXT.md explicitly calls out
 * "apply (BOQ + contract mutation)" as requiring a transaction, since a partial apply (e.g. BOQ
 * items mutated but the contract left unupdated, or vice versa) would leave the commercial
 * record inconsistent with what was actually approved.
 */
class ChangeOrderApplyService
{
    /**
     * Reasonable-default cost-field handling for 'add' items (PROJECT_CONTEXT.md explicitly
     * leaves this to backend-api-engineer's judgment, "document your choice"): a change-order
     * 'add' line only ever carries a client-facing new_unit_price (see ChangeOrderItem's
     * schema — there's no material/labor/other cost breakdown on a change order item, unlike a
     * BoqItem). Rather than inventing a cost split with no data to back it, the new BoqItem's
     * internal cost fields are all seeded at 0 and its client_unit_price is set to the
     * change-order item's new_unit_price. This means direct_cost = 0 for these lines until
     * staff manually edits the new BoqItem's cost fields via the normal PATCH /boq/items/{id}
     * endpoint — an intentional gap (no invented cost data), not a silent wrong number: a
     * client-approved change order records the PRICE impact precisely; the office's own
     * internal costing of that new line is a separate, subsequent BOQ-editing action.
     */
    private const DEFAULT_ADDED_ITEM_COST = '0.00';

    /**
     * Name of the catch-all BOQ category new 'add' items land in. change_order_items carries no
     * category_id (not in the ERD/PRD-specified schema for this table — see model docblock),
     * yet boq_items.category_id is NOT NULL/FK-constrained (Sprint 2 schema), so applying an
     * 'add' item needs SOME category. find-or-create a single per-project "Change Orders"
     * category (created lazily, once per project) rather than requiring staff to pre-select one
     * at change-order-creation time — that would add a field PROJECT_CONTEXT.md's item schema
     * doesn't ask for. Staff can freely re-categorize the resulting BoqItem afterward via the
     * normal BOQ editing endpoints; this category only exists to satisfy the NOT NULL
     * constraint at creation time.
     */
    private const CHANGE_ORDER_CATEGORY_NAME = 'Change Orders';

    /**
     * @throws ChangeOrderApplyException if the project has no contract to apply against
     */
    public function apply(ChangeOrder $changeOrder): ChangeOrder
    {
        return DB::transaction(function () use ($changeOrder) {
            $project = $changeOrder->project;

            // "find the project's contract (if none exists, this should probably fail — a
            // change order can't be applied to a project with no signed contract)" — per
            // PROJECT_CONTEXT.md's own framing of this as the expected resolution. A project's
            // contract_value has nothing to add price_delta onto if no contract was ever
            // signed (Sprint 5: contract creation IS the signing act), so failing loudly here
            // is correct rather than silently mutating the BOQ while leaving no commercial
            // record of the value change anywhere. latest('signed_at') picks the most recently
            // signed contract in the (today practically 0-or-1, per ContractController's own
            // docblock) unlikely case a project has more than one.
            $contract = Contract::query()
                ->where('project_id', $project->id)
                ->latest('signed_at')
                ->first();

            if (! $contract) {
                throw new ChangeOrderApplyException(
                    errorCode: 'CHANGE_ORDER_NO_CONTRACT',
                    message: 'This project has no signed contract yet — a change order can only be applied once a contract exists.'
                );
            }

            foreach ($changeOrder->items()->get() as $item) {
                match ($item->action) {
                    'add' => $this->applyAdd($project, $item),
                    'remove' => $this->applyRemove($item),
                    'modify' => $this->applyModify($item),
                };
            }

            $this->applyToContract($contract, $changeOrder);

            $changeOrder->forceFill(['status' => 'applied', 'applied_at' => now()])->save();

            return $changeOrder->fresh(['items']);
        });
    }

    /**
     * Creates a new live BoqItem from an 'add' change-order item. See class docblock for the
     * cost-field default and category choices.
     */
    private function applyAdd(Project $project, ChangeOrderItem $item): void
    {
        $category = $this->resolveChangeOrderCategory($project);

        BoqItem::create([
            'project_id' => $project->id,
            'category_id' => $category->id,
            'room_id' => null,
            'name' => $item->description,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'material_unit_cost' => self::DEFAULT_ADDED_ITEM_COST,
            'labor_unit_cost' => self::DEFAULT_ADDED_ITEM_COST,
            'other_unit_cost' => self::DEFAULT_ADDED_ITEM_COST,
            'client_unit_price' => $item->new_unit_price,
        ]);
    }

    /**
     * Archives (never hard-deletes) the referenced BoqItem, per BoqItem's established
     * archived_at convention (Sprint 2) — a removed item may already be referenced by a past
     * sent proposal/prior change order and must remain inspectable, just excluded from live
     * BOQ totals (queries already filter whereNull('archived_at')).
     *
     * Guards against boq_item_id being null: nullOnDelete means the referenced BoqItem could
     * have been hard-deleted at the DB level between the change order's creation and this apply
     * call (an edge case, but the FK is nullOnDelete specifically to survive it) — nothing to
     * archive in that case, so this silently no-ops rather than failing the whole apply.
     */
    private function applyRemove(ChangeOrderItem $item): void
    {
        if ($item->boq_item_id === null) {
            return;
        }

        BoqItem::query()
            ->where('id', $item->boq_item_id)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    /**
     * Updates only client_unit_price on the referenced BoqItem — per the model docblock's scope
     * simplification, 'modify' never touches quantity. Same null-guard rationale as
     * applyRemove().
     */
    private function applyModify(ChangeOrderItem $item): void
    {
        if ($item->boq_item_id === null) {
            return;
        }

        BoqItem::query()
            ->where('id', $item->boq_item_id)
            ->update(['client_unit_price' => $item->new_unit_price]);
    }

    /**
     * contract_value += price_delta, via forceFill()+save() (bypassing mass-assignment/
     * FormRequest expectations entirely) — this is the documented, purpose-built exception to
     * UpdateContractRequest's `prohibited` rule on contract_value, not a relaxation of it.
     * bcmath, never float, per the project's money rules.
     *
     * end_date is only extended if it's already set — "don't invent a start date to extend
     * from" (PROJECT_CONTEXT.md verbatim). timeline_delta_days may be null (a change order with
     * no schedule impact) or negative (a pull-forward); Carbon's addDays() handles negative
     * values as subtraction natively, so no separate branch is needed for that case.
     */
    private function applyToContract(Contract $contract, ChangeOrder $changeOrder): void
    {
        $attrs = [
            'contract_value' => bcadd((string) $contract->contract_value, (string) $changeOrder->price_delta, 2),
        ];

        if ($contract->end_date !== null && $changeOrder->timeline_delta_days !== null) {
            $attrs['end_date'] = $contract->end_date->copy()->addDays($changeOrder->timeline_delta_days);
        }

        $contract->forceFill($attrs)->save();
    }

    private function resolveChangeOrderCategory(Project $project): BoqCategory
    {
        return BoqCategory::query()
            ->where('project_id', $project->id)
            ->where('name', self::CHANGE_ORDER_CATEGORY_NAME)
            ->first()
            ?? BoqCategory::create([
                'project_id' => $project->id,
                'parent_id' => null,
                'name' => self::CHANGE_ORDER_CATEGORY_NAME,
                'sort_order' => 9999,
            ]);
    }
}
