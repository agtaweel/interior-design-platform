<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plain template-category fields — used for POST /boq-templates/categories' 201 response. The
 * nested tree (GET /boq-templates/categories) is assembled by
 * App\Services\Boq\BoqTreeService::buildTemplateTree(), same rationale as BoqCategoryResource.
 */
class BoqTemplateCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
