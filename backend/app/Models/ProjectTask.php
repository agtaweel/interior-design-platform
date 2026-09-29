<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProjectTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * No direct organization_id column: scoped indirectly through project_id ->
 * projects.organization_id, same convention as Contract/PurchaseOrder. Photos (BRD S16)
 * reuse the same Spatie Media Library infrastructure Project::MEDIA_COLLECTIONS already
 * established for the Documents tab — one 'photos' collection here, on the private local
 * disk (config/media-library.php's MEDIA_DISK=local override), same access-controlled-
 * indirection posture (see ProjectMediaController).
 */
#[Fillable(['project_id', 'title', 'description', 'assignee_user_id', 'status', 'due_date', 'sort_order'])]
class ProjectTask extends Model implements HasMedia
{
    /** @use HasFactory<ProjectTaskFactory> */
    use HasFactory, Auditable, InteractsWithMedia;

    public const PHOTOS_COLLECTION = 'photos';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
