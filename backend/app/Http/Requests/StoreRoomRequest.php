<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/rooms. project_id is never accepted from the body — it comes from
 * the route, same convention as StoreBoqCategoryRequest/StoreBoqItemRequest. Only `name` is
 * required; `area_m2`/`sort_order` are optional the same way BoqCategory's sort_order is
 * (RoomController::store() calls fresh() afterwards for the same database-default reason as
 * BoqCategoryController::store()).
 */
class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'area_m2' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
