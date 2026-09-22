<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /change-orders/{id} — full INTERNAL detail (items, requester, all lifecycle timestamps),
 * per PROJECT_CONTEXT.md Sprint 7. Internal-only: safe to include boq_item_id (via
 * ChangeOrderItemResource) since nothing here reaches the public/token-authenticated surface
 * (see PublicChangeOrderResource for that boundary).
 */
class ChangeOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'number' => $this->number,
            'status' => $this->status,
            'reason' => $this->reason,
            'price_delta' => $this->price_delta,
            'timeline_delta_days' => $this->timeline_delta_days,
            'items' => ChangeOrderItemResource::collection($this->whenLoaded('items')),
            'requested_by' => new UserSummaryResource($this->whenLoaded('requestedBy')),
            'sent_at' => $this->sent_at,
            'approved_at' => $this->approved_at,
            'applied_at' => $this->applied_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
