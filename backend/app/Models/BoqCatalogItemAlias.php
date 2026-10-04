<?php

namespace App\Models;

use Database\Factories\BoqCatalogItemAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQ Master Catalog + Standard Templates — an alternate search name for a catalog item. Used
 * only for search/matching (see BoqCatalogTreeService::search()) — never load-bearing for
 * template/apply logic. No organization scope of its own: visibility is entirely mediated
 * through the parent catalog item's own BelongsToOrganization scope.
 */
#[Fillable(['catalog_item_id', 'alias_en', 'alias_ar'])]
class BoqCatalogItemAlias extends Model
{
    /** @use HasFactory<BoqCatalogItemAliasFactory> */
    use HasFactory;

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(BoqCatalogItem::class, 'catalog_item_id');
    }
}
