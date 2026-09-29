<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SiteReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * No direct organization_id column — same indirect project_id -> projects.organization_id
 * scoping as ProjectTask. Photos via Spatie Media Library, same as ProjectTask.
 */
#[Fillable(['project_id', 'reported_by_user_id', 'report_date', 'work_done', 'issues', 'decisions'])]
class SiteReport extends Model implements HasMedia
{
    /** @use HasFactory<SiteReportFactory> */
    use HasFactory, Auditable, InteractsWithMedia;

    public const PHOTOS_COLLECTION = 'photos';

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS_COLLECTION);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
