<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /boq-templates/categories. organization_id is never accepted from the body — the
 * controller derives it from the current tenant context, same convention as every other
 * organization-scoped write. Unlike the project-scoped StoreBoqCategoryRequest, parent_id here
 * is scoped by the resolved TenantContext organization id directly (boq_template_categories
 * carries organization_id itself — see BoqTemplateCategory's docblock), not by a route param.
 */
class StoreBoqTemplateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('boq_template_categories', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
