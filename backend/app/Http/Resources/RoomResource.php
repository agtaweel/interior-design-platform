<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plain room fields — used for GET /projects/{project}/rooms (collection) and
 * POST /projects/{project}/rooms' 201 response. Same shape convention as
 * BoqCategoryResource (id/project_id/name/sort_order/timestamps), plus area_m2.
 */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'area_m2' => $this->area_m2,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
