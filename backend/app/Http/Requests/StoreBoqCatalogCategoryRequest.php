<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /boq-catalog/categories (and its /platform mount). organization_id is never accepted
 * from the body — BoqCatalogController derives it from whether the request came through the
 * `tenant` or `platform.owner` mount. Authorized by EITHER Permissions::MANAGE_BOQ (an org
 * member, tenant mount) OR is_platform_owner (platform mount) — User::hasPermission() always
 * returns false with no tenant context resolved, so the platform mount needs this explicit OR
 * rather than relying on can() alone.
 */
class StoreBoqCatalogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ) || (bool) $this->user()->is_platform_owner;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('boq_catalog_categories', 'id')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
