<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `quoted_total`/`actual_total` are computed here (bcmath, via the model's own helper methods)
 * rather than left for the frontend to multiply floats client-side — same "financial
 * correctness before visual complexity" discipline as BoqItemResource/ProposalItemResource.
 * `actual_total` is null until this line has been (at least partially) received.
 */
class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'quoted_unit_price' => $this->quoted_unit_price,
            'quoted_total' => $this->quotedTotal(),
            'received_quantity' => $this->received_quantity,
            'actual_unit_price' => $this->actual_unit_price,
            'actual_total' => $this->actualTotal(),
        ];
    }
}
