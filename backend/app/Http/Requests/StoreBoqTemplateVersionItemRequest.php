<?php

namespace App\Http\Requests;

use App\Models\BoqTemplateItem;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /boq-templates/{template}/versions/{version}/items. catalog_item_id must reference a
 * real catalog item; category_id defaults to that catalog item's own category if omitted (see
 * BoqTemplateAdminController::storeItem()) — both are validated for existence only here, not
 * cross-checked against the organization, since BoqCatalogItem's own BelongsToOrganization scope
 * already means a cross-org id simply won't resolve when the controller looks it up.
 */
class StoreBoqTemplateVersionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ) || (bool) $this->user()->is_platform_owner;
    }

    public function rules(): array
    {
        return [
            'catalog_item_id' => ['required', 'integer', 'exists:boq_catalog_items,id'],
            'category_id' => ['nullable', 'integer', 'exists:boq_catalog_categories,id'],
            'default_unit_id' => ['nullable', 'integer', 'exists:boq_units,id'],
            'default_quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_formula' => ['nullable', 'string', 'max:255'],
            'quantity_source' => ['nullable', Rule::in([
                BoqTemplateItem::SOURCE_FIXED_DEFAULT, BoqTemplateItem::SOURCE_FORMULA,
                BoqTemplateItem::SOURCE_USER_INPUT, BoqTemplateItem::SOURCE_OPTIONAL,
            ])],
            'is_required' => ['nullable', 'boolean'],
            'is_optional' => ['nullable', 'boolean'],
            'is_enabled_by_default' => ['nullable', 'boolean'],
            'material_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'other_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'client_unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
