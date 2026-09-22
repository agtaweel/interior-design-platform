<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/change-orders (PROJECT_CONTEXT.md Sprint 7). project_id/number/
 * status/price_delta are never accepted from the body — price_delta is always computed
 * server-side from items' line_delta (ChangeOrderService), same "compute from lines, don't
 * trust a manual total" principle as proposals' grand_total.
 *
 * Per-item validation mirrors ChangeOrderItem's model docblock exactly:
 *   - 'add': boq_item_id prohibited (no existing item yet), new_unit_price required.
 *   - 'remove': boq_item_id required (existence-checked against THIS project's non-archived
 *     boq_items only, via the route project's id — same scoping rationale as
 *     StoreBoqItemRequest's category_id/room_id checks), new_unit_price prohibited (no new
 *     price, the line is being removed).
 *   - 'modify': boq_item_id required (same existence check as 'remove'), new_unit_price
 *     required.
 *
 * `old_unit_price` is prohibited for EVERY action (not just 'add') — it's always derived
 * server-side from the referenced boq_item's current client_unit_price at write time
 * (ChangeOrderService::resolveOldUnitPrice()), never accepted from the client. This value is
 * commercially load-bearing (feeds line_delta -> price_delta -> contracts.contract_value on
 * apply), so trusting a client-supplied figure here would let it be fabricated.
 *
 * description/quantity/unit are required for every action — the model's line_delta/apply-step
 * math needs them regardless of action, and 'add' has no other source for them.
 *
 * Reuses Permissions::MANAGE_BOQ, consistent with the rest of the commercial workflow
 * (BOQ/pricing/proposals/contracts) — per PROJECT_CONTEXT.md's explicit instruction not to
 * default to a stricter/different gate just because this is a newer sprint.
 */
class StoreChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        $projectId = Project::find($this->route('project'))?->id;

        return [
            'reason' => ['required', 'string'],
            'timeline_delta_days' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.action' => ['required', 'string', Rule::in(['add', 'remove', 'modify'])],
            'items.*.boq_item_id' => [
                'nullable',
                'integer',
                'required_if:items.*.action,remove,modify',
                'prohibited_if:items.*.action,add',
                Rule::exists('boq_items', 'id')->where(
                    fn ($query) => $query->where('project_id', $projectId)->whereNull('archived_at')
                ),
            ],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            // Prohibited for EVERY action, not just 'add': old_unit_price is always derived
            // server-side from the referenced boq_item's current client_unit_price
            // (ChangeOrderService::resolveOldUnitPrice()) since it's commercially load-bearing —
            // it feeds line_delta, which feeds price_delta, which is added directly to
            // contracts.contract_value on apply. Accepting a client-supplied value here would
            // let an internal caller fabricate the commercial delta a client approves. Rejecting
            // outright (rather than silently ignoring) surfaces the mistake instead of masking it.
            'items.*.old_unit_price' => ['prohibited'],
            'items.*.new_unit_price' => [
                'nullable', 'numeric', 'min:0',
                'required_if:items.*.action,add,modify',
                'prohibited_if:items.*.action,remove',
            ],
        ];
    }
}
