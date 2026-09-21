<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/boq/items. project_id is never accepted from the body.
 * category_id/room_id must both belong to THIS project — scoped via the route project's id
 * (resolved through the OrganizationScope-guarded Project::find(), same rationale as
 * StoreBoqCategoryRequest's docblock). supplier_id has no FK yet (suppliers lands in Sprint 6 —
 * see BoqItem's model docblock) so it is only validated as an integer here, not existence-
 * checked.
 */
class StoreBoqItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        $projectId = Project::find($this->route('project'))?->id;

        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('boq_categories', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'max:50'],
            'material_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'other_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'client_unit_price' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
