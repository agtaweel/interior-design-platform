<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BoqCatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOQ Master Catalog + Standard Templates — a leaf Master Catalog item: defines WHAT a line of
 * work is, never a project-specific price. `default_*_cost` columns are optional, non-binding
 * starting suggestions only (see the migration's docblock) — the Project BOQ item (BoqItem)
 * remains the sole financial source of truth regardless. `organization_id` nullable via
 * BelongsToOrganization: null = global/system catalog item, non-null = an organization's own
 * private custom-catalog item.
 */
#[Fillable([
    'organization_id', 'category_id', 'name', 'name_en', 'name_ar',
    'description', 'description_en', 'description_ar', 'default_unit_id',
    'default_material_unit_cost', 'default_labor_unit_cost', 'default_other_unit_cost',
    'default_client_unit_price', 'is_active', 'sort_order', 'created_by',
])]
class BoqCatalogItem extends Model
{
    /** @use HasFactory<BoqCatalogItemFactory> */
    use HasFactory, BelongsToOrganization, Auditable;

    protected function casts(): array
    {
        return [
            'default_material_unit_cost' => 'decimal:2',
            'default_labor_unit_cost' => 'decimal:2',
            'default_other_unit_cost' => 'decimal:2',
            'default_client_unit_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BoqCatalogCategory::class, 'category_id');
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(BoqUnit::class, 'default_unit_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(BoqCatalogItemAlias::class, 'catalog_item_id');
    }

    public function templateItems(): HasMany
    {
        return $this->hasMany(BoqTemplateItem::class, 'catalog_item_id');
    }

    public function localizedName(?string $locale): string
    {
        return ($locale === 'ar' ? $this->name_ar : $this->name_en) ?: $this->name;
    }
}
