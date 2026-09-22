<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/proposals. project_id/version_no/status/totals are never accepted
 * from the body — version_no is computed server-side, status always starts 'draft', totals
 * come from PricingCalculator — only content_json is client-authored input (see
 * PROJECT_CONTEXT.md's Sprint 4 API scope). Reuses Permissions::MANAGE_BOQ — see that
 * constant's docblock for the full rationale (documented once there rather than repeated per
 * FormRequest, matching StorePricingRuleRequest's precedent).
 */
class StoreProposalVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'content_json' => ['nullable', 'array'],
        ];
    }
}
