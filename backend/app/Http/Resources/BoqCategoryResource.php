<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plain category fields only — used for POST /projects/{project}/boq/categories' 201 response.
 * The nested tree shape (items/subtotal/children) returned by GET /projects/{project}/boq is
 * assembled directly as arrays by App\Services\Boq\BoqTreeService instead of reusing this
 * class, since that recursive shape isn't "this model plus whenLoaded relations".
 */
class BoqCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
