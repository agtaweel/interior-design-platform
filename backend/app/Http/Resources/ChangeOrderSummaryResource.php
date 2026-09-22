<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /projects/{id}/change-orders — S14's list shape, per PROJECT_CONTEXT.md: number, status,
 * price_delta, timeline_delta_days, sent_at, approved_at, applied_at. Deliberately excludes
 * reason/items — those are only in the full detail view (ChangeOrderResource, GET
 * /change-orders/{id}).
 */
class ChangeOrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'price_delta' => $this->price_delta,
            'timeline_delta_days' => $this->timeline_delta_days,
            'sent_at' => $this->sent_at,
            'approved_at' => $this->approved_at,
            'applied_at' => $this->applied_at,
        ];
    }
}
