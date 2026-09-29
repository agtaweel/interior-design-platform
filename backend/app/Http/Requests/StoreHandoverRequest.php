<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/handover — BRD S20 "Handover: final approval, warranty, completion
 * document." Gated behind Permissions::MANAGE_PROJECTS (a project-closing lifecycle act, same
 * tier as project status transitions), not MANAGE_EXECUTION — handover is a final sign-off
 * decision distinct from the day-to-day site-engineer workflow that raises/closes snags and
 * files site reports.
 */
class StoreHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROJECTS);
    }

    public function rules(): array
    {
        return [
            'handover_date' => ['required', 'date'],
            'warranty_period_months' => ['nullable', 'integer', 'min:0'],
            'warranty_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
