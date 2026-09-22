<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PaymentScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No organization_id (or project_id) column: this table is scoped indirectly through THREE
 * hops — one level deeper than ProposalItem's two-hop case (proposal_version_id ->
 * proposal_versions.project_id -> projects.organization_id) and two levels deeper than the
 * one-hop precedent (BoqItem/PricingRule/Contract: project_id -> projects.organization_id):
 *
 *   contract_id -> contracts.project_id -> projects.organization_id
 *
 * To resolve the owning organization from a PaymentSchedule instance, walk all three hops,
 * e.g.:
 *
 *   $paymentSchedule->contract->project->organization_id
 *
 * auditOrganizationId() below does exactly this so Auditable can attribute audit_logs rows
 * correctly despite there being no organization_id/project_id column on this model at all.
 *
 * Status semantics (PROJECT_CONTEXT.md Sprint 6, deliberate simplification): only 'pending' and
 * 'paid' are ever stored. 'Overdue' and 'upcoming' are NOT stored statuses — they're computed
 * at read time (see isOverdue()/isUpcoming() below), matching the migration's documented
 * rationale (avoids a scheduled job to flip status purely with the passage of time).
 */
#[Fillable([
    'contract_id', 'name', 'sequence_no', 'due_date', 'percentage', 'amount', 'status',
])]
class PaymentSchedule extends Model
{
    /** @use HasFactory<PaymentScheduleFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'percentage' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column, and no
     * project_id either) — resolve it by walking the full three-hop chain via the parent
     * contract, same pattern as Contract/ProposalVersion's own auditOrganizationId()
     * overrides, just one hop further.
     */
    public function auditOrganizationId(): ?int
    {
        return $this->contract?->project?->organization_id;
    }

    /**
     * Computed, non-persisted: true when this schedule is still owed AND its due date has
     * passed. Per PROJECT_CONTEXT.md, "overdue" is deliberately NOT a stored status — this is
     * the read-time computation the UX's overdue filter/badge is meant to use instead.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date !== null && $this->due_date->isPast();
    }

    /**
     * Computed counterpart to isOverdue(): still owed, but not yet due. Together these two
     * (plus the stored 'paid' status) cover the UX's three filter states without a third
     * database value.
     */
    public function isUpcoming(): bool
    {
        return $this->status === 'pending' && $this->due_date !== null && ! $this->due_date->isPast();
    }
}
