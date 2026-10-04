<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BOQ Master Catalog + Standard Templates — template line item fields, including suggested
 * cost overrides. Staff-only, organization-level template management surface (not
 * client-facing), same posture as BoqCatalogItemResource — see that class's docblock.
 *
 * Replaces the old flat shape entirely (same class name as Sprint 2's simple template system,
 * deliberately reused — see BoqTemplateItem model's docblock).
 */
class BoqTemplateItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_version_id' => $this->template_version_id,
            'category_id' => $this->category_id,
            'catalog_item' => $this->whenLoaded('catalogItem', fn () => new BoqCatalogItemResource($this->catalogItem)),
            'default_unit' => $this->whenLoaded('defaultUnit', fn () => new BoqUnitResource($this->defaultUnit)),
            'default_quantity' => $this->default_quantity,
            'quantity_formula' => $this->quantity_formula,
            'quantity_source' => $this->quantity_source,
            'is_required' => $this->is_required,
            'is_optional' => $this->is_optional,
            'is_enabled_by_default' => $this->is_enabled_by_default,
            'material_unit_cost' => $this->material_unit_cost,
            'labor_unit_cost' => $this->labor_unit_cost,
            'other_unit_cost' => $this->other_unit_cost,
            'client_unit_price' => $this->client_unit_price,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
        ];
    }
}
