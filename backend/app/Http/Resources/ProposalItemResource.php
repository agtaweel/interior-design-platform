<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * INTERNAL proposal item view — includes source_boq_item_id for staff traceability back to
 * the BOQ line it was snapshotted from. There are no cost/margin fields to withhold here (the
 * `proposal_items` table itself never carries them, see ProposalItem's docblock), but
 * source_boq_item_id must NEVER reach a public-facing response — see
 * App\Services\Proposals\ProposalPresenter, which builds the client-shaped item array
 * separately and independently of this class rather than reusing it with an `except()`.
 */
class ProposalItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_boq_item_id' => $this->source_boq_item_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
        ];
    }
}
