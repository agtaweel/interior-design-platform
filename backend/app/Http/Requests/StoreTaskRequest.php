<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/tasks. Multipart body — optional `photos[]` files (BRD S16 "Tasks/
 * Site: ...photos") attached at creation time rather than via a separate upload endpoint, to
 * keep this MVP's surface area tight (a task's photos are typically captured once, when the
 * work is done, not incrementally appended after the fact the way a Documents-tab attachment
 * is).
 */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_EXECUTION);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assignee_user_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
            'status' => ['sometimes', Rule::in(['todo', 'in_progress', 'done'])],
            'due_date' => ['nullable', 'date'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}
