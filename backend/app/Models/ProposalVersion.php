<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProposalVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as BoqItem/PricingRule.
 *
 * Immutability (PROJECT_CONTEXT.md Sprint 4 "Immutability rule"): editable only while
 * status == 'draft'. Once status becomes 'sent', content_json/items/the totals columns and
 * snapshot_json are locked. Enforcing that is backend-api-engineer's job (controllers), not
 * this model's — no DB constraint backs it, matching this codebase's convention of validating
 * state transitions at the application layer.
 *
 * subtotal/markup_total/fees_total/discount_total/grand_total mirror Sprint 3's
 * "null means not yet computed" convention on `projects` — they're populated once at creation
 * time by copying the project's current pricing breakdown, then frozen (never recalculated
 * later, unlike the project cache columns which get overwritten on each recalculate call).
 */
#[Fillable([
    'project_id', 'version_no', 'status', 'subtotal', 'markup_total', 'fees_total',
    'discount_total', 'grand_total', 'content_json', 'snapshot_json', 'created_by',
    'sent_at', 'approved_at',
])]
class ProposalVersion extends Model
{
    /** @use HasFactory<ProposalVersionFactory> */
    use HasFactory, Auditable;

    /**
     * The `approvals.entity_type` value used for approvals recorded against a proposal
     * version, per PROJECT_CONTEXT.md's Sprint 4 scope (`entity_type` example:
     * `"proposal_version"`). Kept as a class constant so Sprint 7's ChangeOrder model can
     * define its own sibling constant rather than any caller hardcoding the string.
     */
    public const ENTITY_TYPE = 'proposal_version';

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'markup_total' => 'decimal:2',
            'fees_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'content_json' => 'array',
            'snapshot_json' => 'array',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    /**
     * Approvals recorded against this proposal version. Not a real Eloquent morph relation —
     * `approvals.entity_type`/`entity_id` are polymorphic-by-convention (plain columns, no
     * morph map), exactly like AuditLog::entity_type/entity_id. This is a HasMany against
     * `entity_id` with an extra `entity_type` constraint bolted on via self::ENTITY_TYPE; it's
     * read-only from here — creating an approval row (during the public approve/
     * request-changes actions) is backend-api-engineer's responsibility, not this relation's.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'entity_id')->where('entity_type', self::ENTITY_TYPE);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column) — resolve
     * it via the parent project instead, same pattern as BoqItem/PricingRule.
     */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
