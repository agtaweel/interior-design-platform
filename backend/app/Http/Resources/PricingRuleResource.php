<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal pricing_rules CRUD view — full rule configuration. There is no client-facing
 * equivalent of this resource: clients never see rule names, methods, values, or base
 * selectors (that's exactly the internal margin/markup detail PROJECT_CONTEXT.md's locked
 * pricing-visibility decision says must never leak). The only thing Sprint 4's client portal
 * will ever read is ProjectPricingClientResource (grand_total + priced_at only).
 */
class PricingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'type' => $this->type,
            'method' => $this->method,
            'value' => $this->value,
            'base_selector' => $this->base_selector,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
