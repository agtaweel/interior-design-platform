<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BoqTemplateCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Directly organization-scoped (carries organization_id itself), NOT project-scoped — unlike
 * BoqCategory. Per PROJECT_CONTEXT.md Sprint 2 "Templates": office-wide templates live at the
 * organization level; "apply template to project" copies rows into a project's boq_categories
 * (new rows, new ids) rather than referencing the template, so editing the cloned project BOQ
 * never mutates the master template.
 */
#[Fillable(['organization_id', 'parent_id', 'name', 'sort_order'])]
class BoqTemplateCategory extends Model
{
    /** @use HasFactory<BoqTemplateCategoryFactory> */
    use HasFactory, BelongsToOrganization;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(BoqTemplateCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BoqTemplateCategory::class, 'parent_id');
    }

    public function templateItems(): HasMany
    {
        return $this->hasMany(BoqTemplateItem::class, 'category_id');
    }
}
