<?php

namespace App\Models;

use Database\Factories\BoqTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOQ Master Catalog + Standard Templates — see the migration's docblock for why each version
 * is a full, independent copy of its items rather than a diff. Mutations to a version's items
 * are only allowed while `status === STATUS_DRAFT` (enforced at the controller/FormRequest
 * layer, not here) — once published, a version is immutable, which is what makes
 * `source_template_version_id` on an applied project's BoqItem a trustworthy, permanent record
 * of exactly what that project saw.
 */
#[Fillable(['template_id', 'version_number', 'status', 'published_at', 'created_by', 'change_notes'])]
class BoqTemplateVersion extends Model
{
    /** @use HasFactory<BoqTemplateVersionFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BoqTemplate::class, 'template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoqTemplateItem::class, 'template_version_id');
    }
}
