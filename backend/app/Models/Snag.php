<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SnagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No direct organization_id column — scoped indirectly through project_id ->
 * projects.organization_id, same convention as ProjectTask/SiteReport. See this table's
 * migration docblock for the "cannot mark complete with unresolved mandatory snags" rule this
 * model backs.
 */
#[Fillable(['project_id', 'description', 'priority', 'owner_user_id', 'due_date', 'is_mandatory'])]
class Snag extends Model
{
    /** @use HasFactory<SnagFactory> */
    use HasFactory, Auditable;

    /** status/resolution_notes/closed_at deliberately excluded from #[Fillable] above — only
     *  ever written by SnagController::close()/reopen(), never accepted directly on
     *  create/update, so a snag's open/closed transition always goes through one controlled
     *  code path (mirrors Lead's converted_* column convention). */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_mandatory' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }

    /**
     * BRD's explicit rule: "Project cannot be marked complete with unresolved mandatory
     * snags." Shared by ProjectController::update() (blocking status='completed') and
     * HandoverController::store() (the handover IS the completion act) — one source of truth
     * for what "unresolved mandatory snag" means, rather than duplicating this query in both
     * places.
     */
    public static function hasOpenMandatorySnags(int $projectId): bool
    {
        return self::query()
            ->where('project_id', $projectId)
            ->where('status', 'open')
            ->where('is_mandatory', true)
            ->exists();
    }
}
