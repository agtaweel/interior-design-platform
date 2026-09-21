<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProjectMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No direct organization_id column: this table is tenant-scoped indirectly through
 * project_id -> projects.organization_id. Use $projectMember->project->organization_id
 * (or a join) rather than adding a redundant column here.
 */
#[Fillable(['project_id', 'user_id', 'role'])]
class ProjectMember extends Model
{
    /** @use HasFactory<ProjectMemberFactory> */
    use HasFactory, Auditable;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column) — resolve
     * it via the parent project instead, per Auditable's documented override contract.
     */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
