<?php

namespace App\Models;

use Database\Factories\ChangeOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No organization_id column, and no direct project_id either: this table is scoped
 * indirectly through TWO hops — change_order_id -> change_orders.project_id ->
 * projects.organization_id — mirroring ProposalItem's two-hop precedent from Sprint 4 exactly
 * (one level deeper than the one-hop precedent set by BoqItem/PricingRule, which go straight
 * to project_id -> projects.organization_id). To resolve the owning organization from a
 * ChangeOrderItem instance, walk both hops, e.g.:
 *
 *   $changeOrderItem->changeOrder->project->organization_id
 *
 * There is no `auditOrganizationId()` override here because this model deliberately does NOT
 * use the Auditable trait (see below) — if that ever changes, implement it by walking the two
 * hops above, not by adding a redundant project_id/organization_id column.
 *
 * Not Auditable: same reasoning as ProposalItem — the parent ChangeOrder's own Auditable trail
 * (and, once sent, the fact that items become locked) already covers this table's row-level
 * churn during the draft-editing phase; these rows are typically only written once at
 * creation/edit while draft, not mutated after. Auditing them separately would be noise on
 * top of the parent's trail.
 *
 * `action` determines which of old_unit_price/new_unit_price/boq_item_id are populated and
 * how `line_delta` is computed (PROJECT_CONTEXT.md Sprint 7):
 *   - 'add':    boq_item_id null, old_unit_price null, line_delta = quantity * new_unit_price
 *               (positive).
 *   - 'remove': boq_item_id required, new_unit_price null,
 *               line_delta = -(quantity * old_unit_price) (negative).
 *   - 'modify': boq_item_id required, both prices set,
 *               line_delta = quantity * (new_unit_price - old_unit_price) (sign follows
 *               whether price went up or down). Quantity itself is never changed by 'modify'
 *               (PROJECT_CONTEXT.md's scope simplification) — a real quantity change is
 *               modeled as a 'remove' + 'add' pair instead.
 * Computing line_delta from these formulas is backend-api-engineer's responsibility
 * (controllers/services), not this model's — no accessor here, matching this codebase's
 * convention of deriving money via bcmath at the write path, not lazily.
 */
#[Fillable([
    'change_order_id', 'action', 'boq_item_id', 'description', 'quantity', 'unit',
    'old_unit_price', 'new_unit_price', 'line_delta',
])]
class ChangeOrderItem extends Model
{
    /** @use HasFactory<ChangeOrderItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'old_unit_price' => 'decimal:2',
            'new_unit_price' => 'decimal:2',
            'line_delta' => 'decimal:2',
        ];
    }

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    /**
     * Traceability only, nullable — null for 'add' actions (no existing item yet), and
     * nullOnDelete at the DB level means this may legitimately be null even for a
     * 'remove'/'modify' item if the referenced BOQ item was later deleted. Never re-read from
     * this relation to refresh a change order item's own recorded values.
     */
    public function sourceBoqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }
}
