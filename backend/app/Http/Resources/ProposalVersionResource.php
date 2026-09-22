<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /proposals/{id} — full INTERNAL detail (items, content, snapshot, totals), per
 * PROJECT_CONTEXT.md. Internal-only: safe to include source_boq_item_id (via
 * ProposalItemResource) and the full snapshot_json blob (which itself has no cost/margin
 * fields — proposal_items never carry any — but does carry the full pricing breakdown, which
 * is fine for staff).
 *
 * snapshot_json is only ever non-null for status != 'draft' (see ProposalSendService) — the
 * `whenNotNull`-style conditional below just reflects that state rather than re-deriving it.
 */
class ProposalVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'version_no' => $this->version_no,
            'status' => $this->status,
            'content_json' => $this->content_json,
            'snapshot_json' => $this->status === 'draft' ? null : $this->snapshot_json,
            'subtotal' => $this->subtotal,
            'markup_total' => $this->markup_total,
            'fees_total' => $this->fees_total,
            'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total,
            'items' => ProposalItemResource::collection($this->whenLoaded('items')),
            'created_by' => new UserSummaryResource($this->whenLoaded('createdBy')),
            'sent_at' => $this->sent_at,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
