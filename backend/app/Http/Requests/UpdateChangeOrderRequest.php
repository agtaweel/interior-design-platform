<?php

namespace App\Http\Requests;

use App\Models\ChangeOrder;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /change-orders/{changeOrder} (PROJECT_CONTEXT.md Sprint 7). The draft-only guard (409
 * CHANGE_ORDER_NOT_EDITABLE otherwise) is a state-conflict, not an authorization concern, so it
 * lives in the controller — this class only validates shape/permission, exactly like
 * UpdateProposalVersionRequest's identical split.
 *
 * `items`, when present, REPLACES the change order's entire item set (ChangeOrderService
 * deletes-then-recreates, mirroring ProposalVersionService::snapshotItemsFromBoq()'s
 * replace-wholesale convention) — so every item in the payload must be fully well-formed, same
 * per-action rules as StoreChangeOrderRequest. When `items` is omitted, existing items are left
 * untouched and price_delta is not recomputed.
 */
class UpdateChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        $projectId = ChangeOrder::find($this->route('changeOrder'))?->project_id;

        return [
            'reason' => ['sometimes', 'string'],
            'timeline_delta_days' => ['sometimes', 'nullable', 'integer'],
            'items' => ['sometimes', 'array', 'min:1'],
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
            // Prohibited for every action — see StoreChangeOrderRequest's docblock for why
            // old_unit_price is never client-supplied (always derived server-side from the
            // referenced boq_item since it's commercially load-bearing).
            'items.*.old_unit_price' => ['prohibited'],
            'items.*.new_unit_price' => [
                'nullable', 'numeric', 'min:0',
                'required_if:items.*.action,add,modify',
                'prohibited_if:items.*.action,remove',
            ],
        ];
    }
}
