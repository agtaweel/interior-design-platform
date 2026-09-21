<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Template line item fields, including cost breakdown. This is a staff-only, organization-
 * level template management surface (not client-facing), so exposing material/labor/other
 * unit costs here is expected and necessary — the "never leak cost fields" rule
 * (PROJECT_CONTEXT.md Sprint 2 "Client-facing exposure") applies to client-facing/public
 * serializers, which this is not. See BoqItemResource's docblock for that distinction.
 */
class BoqTemplateItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'material_unit_cost' => $this->material_unit_cost,
            'labor_unit_cost' => $this->labor_unit_cost,
            'other_unit_cost' => $this->other_unit_cost,
            'client_unit_price' => $this->client_unit_price,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
