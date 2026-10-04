<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBoqCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ) || (bool) $this->user()->is_platform_owner;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'description_en' => ['sometimes', 'nullable', 'string'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'default_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:boq_units,id'],
            'default_material_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'default_labor_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'default_other_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'default_client_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
