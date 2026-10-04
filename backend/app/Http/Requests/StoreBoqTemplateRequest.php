<?php

namespace App\Http\Requests;

use App\Models\BoqTemplate;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /boq-templates (and its /platform mount). organization_id is never accepted from the
 * body — see BoqTemplateAdminController's docblock for the same tenant/platform dual-mount
 * pattern BoqCatalogController established. Authorized by EITHER Permissions::MANAGE_BOQ (tenant
 * mount) OR is_platform_owner (platform mount) — see StoreBoqCatalogCategoryRequest's docblock
 * for why the OR is necessary.
 */
class StoreBoqTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ) || (bool) $this->user()->is_platform_owner;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'description_ar' => ['nullable', 'string'],
            'template_type' => ['required', Rule::in([
                BoqTemplate::TYPE_FULL_FINISHING, BoqTemplate::TYPE_RENOVATION, BoqTemplate::TYPE_PARTIAL_FINISHING,
                BoqTemplate::TYPE_ROOM, BoqTemplate::TYPE_TRADE, BoqTemplate::TYPE_PACKAGE,
                BoqTemplate::TYPE_PREMIUM, BoqTemplate::TYPE_LUXURY, BoqTemplate::TYPE_CUSTOM,
            ])],
            'project_type' => ['nullable', 'string', 'max:255'],
            'finishing_level' => ['nullable', Rule::in(['BASIC', 'STANDARD', 'PREMIUM', 'LUXURY'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
