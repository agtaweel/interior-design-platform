<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal-only shape for a change_order_item, used inside ChangeOrderResource (GET
 * /change-orders/{id}). Includes boq_item_id — safe here since this resource is never used on
 * the public/token-authenticated surface (see PublicChangeOrderResource for that boundary).
 */
class ChangeOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'boq_item_id' => $this->boq_item_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'old_unit_price' => $this->old_unit_price,
            'new_unit_price' => $this->new_unit_price,
            'line_delta' => $this->line_delta,
        ];
    }
}
