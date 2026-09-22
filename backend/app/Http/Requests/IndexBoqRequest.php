<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /projects/{project}/boq — PROJECT_CONTEXT.md Sprint 8 "Permissions hardening": this used
 * to allow any active member to read (matching IndexProjectsRequest/IndexClientsRequest), but
 * BoqController's response includes material_unit_cost/labor_unit_cost/other_unit_cost — the
 * exact internal cost/margin fields the Definition of Done says site users must never see. Now
 * requires Permissions::MANAGE_BOQ, matching every BOQ *write* endpoint's gate (BoqItemController
 * etc). A Site Staff role (permissions_json all false per RoleSeeder) is now correctly 403'd
 * here instead of silently receiving the full cost breakdown.
 */
class IndexBoqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }
}
