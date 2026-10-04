<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * INTERNAL BOQ item view — full cost breakdown (material/labor/other unit costs, supplier_id)
 * plus the client-facing fields. This is the only BoqItem resource that exists in Sprint 2
 * because there is no client-facing BOQ consumer yet (per the backend-api-engineer working
 * agreement and PROJECT_CONTEXT.md Sprint 2 "Client-facing exposure").
 *
 * When a client-facing/public BOQ view is needed (Sprint 3+, once proposals/the client portal
 * read BOQ data), add a sibling `BoqItemClientResource` that exposes ONLY name, description,
 * quantity, unit, client_unit_price, and client_total — never material_unit_cost,
 * labor_unit_cost, other_unit_cost, or supplier_id. Do NOT add an `only`/`except` flag to
 * *this* class to toggle between views; a dedicated class keeps the leak-prevention rule
 * impossible to accidentally bypass with a stray parameter.
 */
class BoqItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'category_id' => $this->category_id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'material_unit_cost' => $this->material_unit_cost,
            'labor_unit_cost' => $this->labor_unit_cost,
            'other_unit_cost' => $this->other_unit_cost,
            'client_unit_price' => $this->client_unit_price,
            'direct_cost' => $this->direct_cost,
            'client_total' => $this->client_total,
            'supplier_id' => $this->supplier_id,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
            'archived_at' => $this->archived_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'source_template_id' => $this->source_template_id,
            'source_template_version_id' => $this->source_template_version_id,
            'source_catalog_item_id' => $this->source_catalog_item_id,
            'source_template' => $this->whenLoaded('sourceTemplate', fn () => [
                'id' => $this->sourceTemplate->id,
                'name' => $this->sourceTemplate->name,
            ]),
            'source_catalog_item' => $this->whenLoaded('sourceCatalogItem', fn () => [
                'id' => $this->sourceCatalogItem->id,
                'name' => $this->sourceCatalogItem->name,
            ]),
        ];
    }
}
