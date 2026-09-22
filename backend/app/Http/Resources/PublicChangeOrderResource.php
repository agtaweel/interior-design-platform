<?php

namespace App\Http\Resources;

use App\Models\ChangeOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /public/change-orders/{token} — the S14 public approval page payload, per
 * PROJECT_CONTEXT.md Sprint 7. This IS the leak-prevention boundary for the public surface
 * (mirrors PublicProposalResource's role): number/reason/timeline_delta_days/price_delta are
 * shown to the client (unlike BOQ cost fields, a price delta is the whole point of what a
 * change order asks them to approve — PROJECT_CONTEXT.md is explicit about this), and items are
 * rendered in client-shaped form (description/quantity/unit/old_unit_price/new_unit_price/
 * line_delta) with boq_item_id/internal linkage always stripped.
 *
 * $this->resource here is the ChangeOrder Eloquent model itself (unlike PublicProposalResource,
 * which wraps a presenter-built array) — change_orders has no snapshot_json to build/read (see
 * ChangeOrderSendService's docblock for why), so the live model + its `items` relation (must be
 * eager-loaded by the controller) is the single source of truth for both the internal and
 * public views.
 */
class PublicChangeOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'reason' => $this->reason,
            'timeline_delta_days' => $this->timeline_delta_days,
            'price_delta' => $this->price_delta,
            'items' => $this->items->map(fn (ChangeOrderItem $item): array => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'old_unit_price' => $item->old_unit_price,
                'new_unit_price' => $item->new_unit_price,
                'line_delta' => $item->line_delta,
            ])->values()->all(),
            'sent_at' => $this->sent_at,
            'approved_at' => $this->approved_at,
        ];
    }
}
