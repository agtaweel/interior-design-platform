<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProjectServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No direct organization_id column: tenant-scoped indirectly through
 * project_id -> projects.organization_id, same as ProjectMember. Because there is no
 * organization_id column for the global OrganizationScope to filter on, tenant isolation for
 * this model comes entirely from always querying/creating through a Project that has already
 * been resolved (and therefore org-scoped) by the controller — see ProjectServiceController.
 */
#[Fillable(['project_id', 'service_type', 'pricing_method', 'price', 'metadata_json'])]
class ProjectService extends Model
{
    /** @use HasFactory<ProjectServiceFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'metadata_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
