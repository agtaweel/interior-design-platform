<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Directly organization-scoped (per the ERD), unlike proposal_versions/proposal_items —
 * carries organization_id itself, same pattern as AuditLog.
 *
 * `entity_type`/`entity_id` are polymorphic-by-convention (plain columns), not a real Eloquent
 * morph — same pattern already used by AuditLog::entity_type/entity_id. Kept generic
 * (not proposal-specific) since Sprint 7's change orders reuse this same table.
 *
 * Not Auditable: approvals ARE the audit record for approval actions — auditing the audit
 * would be redundant/recursive.
 */
#[Fillable([
    'organization_id', 'project_id', 'entity_type', 'entity_id', 'approver_type',
    'user_id', 'status', 'comment', 'approved_at', 'ip_address',
])]
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory, BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Nullable: a client approval isn't a `users` row (approver_type == 'client'), so this is
     * only populated for approver_type == 'internal'.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
