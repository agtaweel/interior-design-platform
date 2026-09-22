<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /pricing/rules/{rule}. Deliberately NOT nested under /projects/{project} (see
 * routes/api.php), so — matching UpdateBoqItemRequest's convention exactly — there is no route
 * project param to trust for scoping. Unlike boq_items' category_id/room_id fields, none of
 * this table's editable fields (name/type/method/value/base_selector/sort_order/active)
 * reference another project-scoped table, so there is no cross-project exists-check needed
 * here; tenant isolation is enforced entirely by PricingRuleController::update()'s manual
 * resolve-then-reverify-via-Project::find() lookup before this request's validated() data ever
 * reaches an update() call.
 */
class UpdatePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['markup', 'fee', 'discount'])],
            'method' => ['sometimes', 'string', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'base_selector' => ['sometimes', 'string', Rule::in(['boq_direct_cost', 'boq_client_subtotal', 'running_subtotal'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
