<?php

namespace App\Models;

use Database\Factories\BoqTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQ Master Catalog + Standard Templates — a template line item: references a Master Catalog
 * item (never duplicates its name/description) plus template-specific data (required/optional,
 * quantity source, optional per-template cost overrides). Belongs to a specific
 * BoqTemplateVersion, not directly to a BoqTemplate — see that model's docblock.
 *
 * Replaces the old flat, organization-scoped BoqTemplateItem shape entirely (this is the SAME
 * class name as Sprint 2's simple template system, deliberately reused rather than renamed —
 * see the BOQ Master Catalog + Standard Templates plan's "replace, don't dual-run" decision).
 */
#[Fillable([
    'template_version_id', 'catalog_item_id', 'category_id', 'default_unit_id',
    'default_quantity', 'quantity_formula', 'quantity_source',
    'is_required', 'is_optional', 'is_enabled_by_default',
    'material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'client_unit_price',
    'notes', 'sort_order',
])]
class BoqTemplateItem extends Model
{
    /** @use HasFactory<BoqTemplateItemFactory> */
    use HasFactory;

    public const SOURCE_FIXED_DEFAULT = 'FIXED_DEFAULT';

    public const SOURCE_FORMULA = 'FORMULA';

    public const SOURCE_USER_INPUT = 'USER_INPUT';

    public const SOURCE_OPTIONAL = 'OPTIONAL';

    protected function casts(): array
    {
        return [
            'default_quantity' => 'decimal:2',
            'material_unit_cost' => 'decimal:2',
            'labor_unit_cost' => 'decimal:2',
            'other_unit_cost' => 'decimal:2',
            'client_unit_price' => 'decimal:2',
            'is_required' => 'boolean',
            'is_optional' => 'boolean',
            'is_enabled_by_default' => 'boolean',
        ];
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(BoqTemplateVersion::class, 'template_version_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(BoqCatalogItem::class, 'catalog_item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BoqCatalogCategory::class, 'category_id');
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(BoqUnit::class, 'default_unit_id');
    }
}
