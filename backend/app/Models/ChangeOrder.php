<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ChangeOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as BoqItem/PricingRule/
 * ProposalVersion/Contract.
 *
 * Lifecycle (PROJECT_CONTEXT.md Sprint 7): draft -> sent -> approved|rejected -> applied
 * (only reachable from approved). Enforcing that transition ordering is
 * backend-api-engineer's job (controllers), not this model's — no DB constraint backs it,
 * matching this codebase's convention of validating state transitions at the application
 * layer (see ProposalVersion/Contract's identical disclaimer).
 *
 * price_delta mirrors proposal_versions' "computed from lines, never manually entered, null
 * means not yet computed" convention — it's the sum of items()'s line_delta, recomputed
 * whenever items change while status == 'draft', then frozen once sent.
 *
 * No approved_by column: the `approvals` table (entity_type = self::ENTITY_TYPE) is already
 * the detailed record of WHO approved — same pattern as ProposalVersion.approved_at having no
 * paired user_id, since a client approver isn't a `users` row.
 */
#[Fillable([
    'project_id', 'number', 'status', 'reason', 'price_delta', 'timeline_delta_days',
    'requested_by', 'sent_at', 'approved_at', 'applied_at',
])]
class ChangeOrder extends Model
{
    /** @use HasFactory<ChangeOrderFactory> */
    use HasFactory, Auditable;

    /**
     * The `approvals.entity_type` value used for approvals recorded against a change order,
     * per PROJECT_CONTEXT.md's Sprint 7 scope — this is exactly why ProposalVersion::ENTITY_TYPE
     * / the approvals table's entity_type/entity_id columns were built generic back in
     * Sprint 4, not proposal-specific.
     */
    public const ENTITY_TYPE = 'change_order';

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'timeline_delta_days' => 'integer',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChangeOrderItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Approvals recorded against this change order. Not a real Eloquent morph relation —
     * `approvals.entity_type`/`entity_id` are polymorphic-by-convention (plain columns, no
     * morph map), exactly like AuditLog::entity_type/entity_id and
     * ProposalVersion::approvals(). This is a HasMany against `entity_id` with an extra
     * `entity_type` constraint bolted on via self::ENTITY_TYPE; it's read-only from here —
     * creating an approval row (during the public approve/reject actions) is
     * backend-api-engineer's responsibility, not this relation's.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'entity_id')->where('entity_type', self::ENTITY_TYPE);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column) — resolve
     * it via the parent project instead, same pattern as BoqItem/PricingRule/ProposalVersion/
     * Contract.
     */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
