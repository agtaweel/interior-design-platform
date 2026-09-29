<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /leads/{lead}. `status` may be set to any pipeline value EXCEPT 'converted' here —
 * that transition only ever happens through POST /leads/{lead}/convert (LeadController::
 * convert()), which is the only place that also creates the linked Client/Project and stamps
 * converted_at/converted_client_id/converted_project_id together atomically. Allowing a bare
 * status='converted' PATCH would leave a lead in that terminal state with no actual client
 * behind it — a data-integrity gap the BRD's "convert without data loss" requirement rules out.
 */
class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_LEADS);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'lost'])],
            'estimated_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'owner_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
        ];
    }
}
