<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSnagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_EXECUTION);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'description' => ['required', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
            'due_date' => ['nullable', 'date'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ];
    }
}
