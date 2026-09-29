<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /leads. organization_id is never accepted from the body — LeadController derives it
 * from the current tenant context, same convention as every other organization-scoped write.
 */
class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_LEADS);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'converted', 'lost'])],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
        ];
    }
}
