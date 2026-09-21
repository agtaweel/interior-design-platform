<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /boq-templates/categories/{category}/items. category_id/organization_id are never
 * accepted from the body — the controller resolves {category} via the OrganizationScope-
 * guarded BoqTemplateCategory::find() and derives organization_id from it, same pattern as
 * ProjectServiceController resolving {project} for its nested create.
 */
class StoreBoqTemplateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:50'],
            'material_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'other_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'client_unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
