<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /snags/{snag}. Edits the snag's own fields only — `status`/`resolution_notes`/
 * `closed_at` are never accepted here (see Snag model's docblock); those only ever change via
 * POST /snags/{snag}/close or /reopen.
 */
class UpdateSnagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_EXECUTION);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'description' => ['sometimes', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'owner_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ];
    }
}
