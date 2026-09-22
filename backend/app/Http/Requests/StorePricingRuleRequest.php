<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/pricing/rules. project_id is never accepted from the body (comes
 * from the route, same convention as StoreBoqCategoryRequest/StoreBoqItemRequest). Pricing
 * mutations reuse Permissions::MANAGE_BOQ rather than introducing a new permission — pricing is
 * part of the same designer/estimator workflow as BOQ editing (PROJECT_CONTEXT.md Sprint 3 is
 * explicit that pricing rules sit "on top of" BOQ line-item pricing), so gating them separately
 * would only fragment one workflow's permission story without a concrete need driving it yet.
 *
 * `type`/`method`/`base_selector` are plain strings at the DB layer (PricingRule model
 * docblock — no native enum/CHECK constraint, matching the `projects.status` precedent), so
 * the allowed-value enforcement lives entirely here via Rule::in(), per db-architect's note.
 */
class StorePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['markup', 'fee', 'discount'])],
            'method' => ['required', 'string', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['required', 'numeric', 'min:0'],
            'base_selector' => ['required', 'string', Rule::in(['boq_direct_cost', 'boq_client_subtotal', 'running_subtotal'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
