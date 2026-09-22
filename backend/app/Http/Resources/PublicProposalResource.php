<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /public/proposals/{token} — the S11 client portal payload. Wraps the array produced by
 * App\Services\Proposals\ProposalPresenter::present() (either the frozen `snapshot_json` for a
 * sent+ version, or a live-built equivalent shape for a draft preview — see that class).
 *
 * This is the leak-prevention boundary for the public surface (mirrors
 * ProjectPricingClientResource's role for Sprint 3's pricing block): the presenter's payload
 * carries the FULL pricing breakdown (subtotal/markup/fees/discount/grand_total) because the
 * same payload also backs the internal snapshot_json view — this resource is what strips that
 * down to ONLY `grand_total`, and drops nothing else since the presenter's `items`/`content`
 * are already client-shaped (no source_boq_item_id, no cost fields) at the source.
 *
 * $this->resource here is the plain associative array `present()` returns, not an Eloquent
 * model — JsonResource supports wrapping arrays directly (accessed via array syntax below,
 * not `->property` magic, since arrays don't support that).
 */
class PublicProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->resource;

        return [
            // Passed through as-is: {id, name, legal_name, logo_url, phone, email, currency} —
            // no cost/margin data lives on the organization block, just branding/contact info
            // needed to render the client-facing header.
            'organization' => $payload['organization'],
            'project' => $payload['project'],
            'client' => $payload['client'],
            'property' => $payload['property'],
            'proposal' => [
                'version_no' => $payload['proposal']['version_no'],
                'status' => $payload['proposal']['status'],
                'sent_at' => $payload['proposal']['sent_at'],
                'approved_at' => $payload['proposal']['approved_at'],
            ],
            'content' => $payload['content'],
            'items' => $payload['items'],
            // ONLY grand_total — never subtotal/markup_total/fees_total/discount_total, per
            // PROJECT_CONTEXT.md's explicit instruction (mirrors ProjectPricingClientResource).
            'pricing' => [
                'grand_total' => $payload['pricing']['grand_total'] ?? 0,
            ],
        ];
    }
}
