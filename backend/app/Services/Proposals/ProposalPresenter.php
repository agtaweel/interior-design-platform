<?php

namespace App\Services\Proposals;

use App\Models\ProposalItem;
use App\Models\ProposalVersion;

/**
 * Builds the single client-shaped payload used by THREE surfaces that must all show exactly
 * the same thing (PROJECT_CONTEXT.md Sprint 4: the public JSON endpoint, the PDF, and — before
 * a version is ever sent — the internal "preview exactly what the client will see" affordance
 * S09 asks for): project/client/property/organization header info, `content_json`,
 * client-shaped line items (description/quantity/unit/unit_price/line_total — never
 * source_boq_item_id or any cost/margin field), and ONLY the `grand_total` from the pricing
 * block (never subtotal/markup/fees/discount).
 *
 * ## Frozen (snapshot_json) vs live payload
 *
 * present() is the one method controllers should call. Its rule: once a version has left
 * `draft` (status sent/approved/changes_requested), `snapshot_json` is already finalized (see
 * ProposalSendService::send()) and is returned verbatim — this is what makes the commercially
 * significant document immutable even if the project's underlying BOQ/pricing/client details
 * change afterward (PROJECT_CONTEXT.md's Sprint 4 "Immutability rule"). While still `draft`
 * (no snapshot exists yet — nothing has been sent), present() falls back to building a live
 * payload from current relations, which is exactly what a "preview" of a draft SHOULD show:
 * whatever the BOQ/pricing look like right now, since nothing is frozen yet.
 *
 * buildPayload() (the live builder) is also reused by ProposalSendService to actually produce
 * the `snapshot_json` blob at send time — one shape-building method, never duplicated.
 */
class ProposalPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(ProposalVersion $version): array
    {
        $payload = $version->snapshot_json ?? $this->buildPayload($version);

        // Always overlay LIVE status/sent_at/approved_at, even when the rest of the payload
        // comes from a frozen snapshot_json. The immutability rule (PROJECT_CONTEXT.md) freezes
        // commercial CONTENT (content_json/items/totals) at send time — it does NOT mean the
        // client should keep seeing "sent" forever after they've actually approved it, or after
        // request-changes flips the status. Without this overlay, a sent-then-approved
        // version's public view/PDF would incorrectly keep showing status="sent"/
        // approved_at=null forever, since snapshot_json is only ever written once (at send).
        $payload['proposal'] = [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'status' => $version->status,
            'sent_at' => $version->sent_at,
            'approved_at' => $version->approved_at,
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(ProposalVersion $version): array
    {
        $version->loadMissing(['items', 'project.client', 'project.property', 'project.organization']);
        $project = $version->project;
        $organization = $project->organization;
        $client = $project->client;
        $property = $project->property;

        return [
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'logo_url' => $organization->logo_url,
                'phone' => $organization->phone,
                'email' => $organization->email,
                // EGP-default per PROJECT_CONTEXT.md's locked "Egypt market, EGP default
                // currency" decision — the PDF/public view renders money using whatever the
                // organization has configured here rather than hardcoding "EGP".
                'currency' => $organization->currency,
            ] : null,
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'email' => $client->email,
            ] : null,
            'property' => $property ? [
                'id' => $property->id,
                'type' => $property->type,
                'compound' => $property->compound,
                'address' => $property->address,
            ] : null,
            'proposal' => [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'status' => $version->status,
                'sent_at' => $version->sent_at,
                'approved_at' => $version->approved_at,
            ],
            'content' => $version->content_json,
            // Client-shaped items only — no id/source_boq_item_id, no cost fields (the model
            // itself carries none, but source_boq_item_id is deliberately dropped here too;
            // PROJECT_CONTEXT.md is explicit that the public view must never include it).
            'items' => $version->items->map(fn (ProposalItem $item): array => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->values()->all(),
            // Full breakdown kept here (subtotal/markup/fees/discount) because this same
            // payload doubles as snapshot_json — the INTERNAL detail view (GET
            // /proposals/{id}) reads the full breakdown straight off this blob. Client-facing
            // consumers (PublicProposalResource, the PDF view) are responsible for exposing
            // ONLY `grand_total` from this sub-array — see those classes' docblocks. This
            // mirrors ProjectPricingClientResource's split: the leak-prevention boundary lives
            // in the resource/view layer, not by omitting data the internal side also needs.
            'pricing' => [
                'subtotal' => $version->subtotal,
                'markup_total' => $version->markup_total,
                'fees_total' => $version->fees_total,
                'discount_total' => $version->discount_total,
                'grand_total' => $version->grand_total,
            ],
        ];
    }
}
