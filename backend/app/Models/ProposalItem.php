<?php

namespace App\Models;

use Database\Factories\ProposalItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No organization_id column, and no direct project_id either: this table is scoped
 * indirectly through TWO hops — proposal_version_id -> proposal_versions.project_id ->
 * projects.organization_id — one level deeper than the one-hop precedent set by
 * BoqItem/PricingRule (which go straight to project_id -> projects.organization_id). To
 * resolve the owning organization from a ProposalItem instance, walk both hops, e.g.:
 *
 *   $proposalItem->proposalVersion->project->organization_id
 *
 * There is no `auditOrganizationId()` override here because this model deliberately does NOT
 * use the Auditable trait (see below) — if that ever changes, implement it by walking the two
 * hops above, not by adding a redundant project_id/organization_id column.
 *
 * Not Auditable: these rows are a frozen copy made at proposal-version-creation time and are
 * never mutated once the parent version leaves `draft` (PROJECT_CONTEXT.md's immutability
 * rule). The parent ProposalVersion's `snapshot_json` (captured at send/approve time) is the
 * audit trail for what a client actually saw — auditing this table's row-level churn during
 * the draft-editing phase would be noise on top of that.
 *
 * Client-facing shape only (description/quantity/unit/unit_price/line_total) — unlike
 * BoqItem, this table must NEVER carry cost/margin columns, since it mirrors exactly what
 * the public proposal endpoint and PDF render to the client.
 */
#[Fillable([
    'proposal_version_id', 'source_boq_item_id', 'description', 'quantity', 'unit',
    'unit_price', 'line_total',
])]
class ProposalItem extends Model
{
    /** @use HasFactory<ProposalItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class);
    }

    /**
     * Traceability only, nullable — never re-read from this relation to refresh a frozen
     * proposal item, and nullOnDelete at the DB level means this may legitimately be null even
     * for an item that originally came from a BOQ item, if that BOQ item was later deleted.
     */
    public function sourceBoqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'source_boq_item_id');
    }
}
