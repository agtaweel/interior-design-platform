<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoqCatalogItemRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'description_ar' => ['nullable', 'string'],
            'default_unit_id' => ['nullable', 'integer', 'exists:boq_units,id'],
            'default_material_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'default_labor_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'default_other_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'default_client_unit_price' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
