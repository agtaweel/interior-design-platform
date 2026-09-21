<?php

namespace App\Models;

use Database\Factories\BoqCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as ProjectMember/ProjectService.
 *
 * Self-referential parent_id supports nested categories (e.g. "Flooring" > "Tiling").
 */
#[Fillable(['project_id', 'parent_id', 'name', 'sort_order'])]
class BoqCategory extends Model
{
    /** @use HasFactory<BoqCategoryFactory> */
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(BoqCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BoqCategory::class, 'parent_id');
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class, 'category_id');
    }
}
