<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PricingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as BoqCategory/BoqItem/Room.
 *
 * `type`, `method`, and `base_selector` are plain strings rather than a DB-level enum/CHECK
 * constraint or a PHP native enum class, matching the exact precedent set by
 * `projects.status`: allowed values are documented here and validated at the application layer
 * by backend-api-engineer's controllers (e.g. Rule::in()). Valid values:
 *   - type: markup | fee | discount ("Supervision" is just a fee named that — the base stays
 *     configurable per the locked product decision rather than hardcoding what supervision is
 *     computed on).
 *   - method: percentage | fixed_amount
 *   - base_selector: boq_direct_cost (sum of item direct costs) | boq_client_subtotal (sum of
 *     item client_totals — the fixed anchor, unaffected by other rules) | running_subtotal (the
 *     total after previously-applied rules, for cascading/compounding rules).
 *
 * This model intentionally has no recalculation logic — computing direct_cost_total,
 * client_subtotal, and the rule-by-rule running_subtotal walk is backend-api-engineer's
 * responsibility (POST /projects/{id}/pricing/recalculate), not a model concern. See
 * docs/PROJECT_CONTEXT.md "Sprint 3 scope" for the full algorithm this table feeds.
 *
 * Wired with Auditable: rule changes affect commercial pricing (a markup% edit or a rule
 * deactivation changes what a client is charged on next recalculation), which is exactly the
 * kind of mutation the NFRs require an audit trail for.
 */
#[Fillable([
    'project_id', 'name', 'type', 'method', 'value', 'base_selector', 'sort_order', 'active',
])]
class PricingRule extends Model
{
    /** @use HasFactory<PricingRuleFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column) — resolve
     * it via the parent project instead, same pattern as BoqItem::auditOrganizationId().
     */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
