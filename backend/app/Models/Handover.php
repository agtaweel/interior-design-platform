<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\HandoverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No direct organization_id column — scoped indirectly through project_id ->
 * projects.organization_id, same convention as Snag/ProjectTask/SiteReport. unique(project_id)
 * at the DB level (see migration) enforces "one handover per project."
 */
#[Fillable([
    'project_id', 'approved_by_user_id', 'handover_date', 'warranty_period_months',
    'warranty_notes', 'notes',
])]
class Handover extends Model
{
    /** @use HasFactory<HandoverFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'handover_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
