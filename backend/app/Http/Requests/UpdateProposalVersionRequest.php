<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /proposals/{id}. The draft-only guard (409 PROPOSAL_NOT_EDITABLE otherwise) is a
 * state-conflict, not an authorization concern, so it lives in the controller — this class only
 * validates shape/permission, exactly like every other authorize()+rules() split in this
 * codebase (see UpdateBoqItemRequest/UpdatePricingRuleRequest).
 *
 * `resnapshot`: optional boolean flag — when true, the controller also re-pulls the project's
 * CURRENT non-archived boq_items/pricing into this draft's items/totals (PROJECT_CONTEXT.md:
 * "update content_json and optionally re-snapshot items/pricing"). Defaults to false so a
 * pure content_json edit (the common case — editing cover note/terms text) never
 * unintentionally re-touches the commercial figures.
 */
class UpdateProposalVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'content_json' => ['sometimes', 'nullable', 'array'],
            'resnapshot' => ['sometimes', 'boolean'],
        ];
    }
}
