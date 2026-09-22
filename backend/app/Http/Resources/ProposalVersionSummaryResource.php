<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /projects/{id}/proposals — S10 Proposal Version History's list shape, per
 * PROJECT_CONTEXT.md: id, version_no, status, grand_total, created_by, sent_at, approved_at.
 * Deliberately excludes content_json/snapshot_json/items — those are only in the full detail
 * view (ProposalVersionResource, GET /proposals/{id}).
 */
class ProposalVersionSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_no' => $this->version_no,
            'status' => $this->status,
            'grand_total' => $this->grand_total,
            'created_by' => new UserSummaryResource($this->whenLoaded('createdBy')),
            'sent_at' => $this->sent_at,
            'approved_at' => $this->approved_at,
        ];
    }
}
