<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BoqCatalogCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOQ Master Catalog + Standard Templates — a category (or, when `parent_id` is set, a
 * subcategory — the product spec's "Category > Subcategory" is modeled as one self-referential
 * tree, same convention as BoqCategory/BoqTemplateCategory, not two separate tables) in the
 * Master Catalog. `organization_id` nullable via BelongsToOrganization: null = global/system
 * category visible to every organization, non-null = that organization's own private
 * custom-catalog category — see the migration's docblock.
 */
#[Fillable(['organization_id', 'parent_id', 'name', 'name_en', 'name_ar', 'sort_order', 'is_active'])]
class BoqCatalogCategory extends Model
{
    /** @use HasFactory<BoqCatalogCategoryFactory> */
    use HasFactory, BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(BoqCatalogCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BoqCatalogCategory::class, 'parent_id');
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(BoqCatalogItem::class, 'category_id');
    }

    public function localizedName(?string $locale): string
    {
        return ($locale === 'ar' ? $this->name_ar : $this->name_en) ?: $this->name;
    }
}
