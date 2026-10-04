<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BOQ Master Catalog + Standard Templates — a staff-only, organization-level catalog management
 * surface (not client-facing), so exposing default cost suggestions here is expected, same
 * posture as BoqTemplateItemResource (see that class's docblock for the client-facing
 * distinction this does NOT need to make).
 */
class BoqCatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'organization_id' => $this->organization_id,
            'is_system' => $this->organization_id === null,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'description' => $this->description,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'default_unit' => $this->whenLoaded('defaultUnit', fn () => new BoqUnitResource($this->defaultUnit)),
            'default_material_unit_cost' => $this->default_material_unit_cost,
            'default_labor_unit_cost' => $this->default_labor_unit_cost,
            'default_other_unit_cost' => $this->default_other_unit_cost,
            'default_client_unit_price' => $this->default_client_unit_price,
            'is_active' => $this->is_active,
        ];
    }
}
