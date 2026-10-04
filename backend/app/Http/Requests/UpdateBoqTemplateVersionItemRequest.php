<?php

namespace App\Http\Requests;

use App\Models\BoqTemplateItem;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoqTemplateVersionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ) || (bool) $this->user()->is_platform_owner;
    }

    public function rules(): array
    {
        return [
            'default_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:boq_units,id'],
            'default_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'quantity_formula' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity_source' => ['sometimes', Rule::in([
                BoqTemplateItem::SOURCE_FIXED_DEFAULT, BoqTemplateItem::SOURCE_FORMULA,
                BoqTemplateItem::SOURCE_USER_INPUT, BoqTemplateItem::SOURCE_OPTIONAL,
            ])],
            'is_required' => ['sometimes', 'boolean'],
            'is_optional' => ['sometimes', 'boolean'],
            'is_enabled_by_default' => ['sometimes', 'boolean'],
            'material_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'labor_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'other_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'client_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
