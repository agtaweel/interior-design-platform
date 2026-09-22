<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as BoqItem/PricingRule/
 * ProposalVersion.
 *
 * Immutability boundary (PROJECT_CONTEXT.md Sprint 5): contract_value and proposal_version_id
 * are permanently locked at creation (proposal_version_id is also DB-unique — see the
 * migration — enforcing the ERD's "Approved Proposal Version 1—0..1 Contract" relationship).
 * Enforcing that contract_value/proposal_version_id are never mutated after creation is
 * backend-api-engineer's job (controllers), not this model's — no DB constraint backs the
 * "never recalculated" half, matching this codebase's existing convention of validating
 * business rules at the application layer (see ProposalVersion's identical disclaimer).
 * start_date/end_date/terms_json are, by contrast, freely editable — Auditable's before/after
 * trail is what makes those edits "controlled amendments" per this sprint's scope decision.
 */
#[Fillable([
    'project_id', 'proposal_version_id', 'contract_no', 'status', 'contract_value',
    'signed_at', 'start_date', 'end_date', 'terms_json',
])]
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'contract_value' => 'decimal:2',
            'signed_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'terms_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class);
    }

    /**
     * Sprint 6: a contract's installment plan. See PaymentSchedule's docblock for the
     * three-hop indirect tenancy scoping this relation sits underneath.
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column) — resolve
     * it via the parent project instead, same pattern as BoqItem/PricingRule/ProposalVersion.
     */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
