<?php

namespace App\Services\Boq;

use App\Http\Resources\BoqCatalogItemResource;
use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Models\BoqCatalogItemAlias;
use Illuminate\Support\Collection;

/**
 * BOQ Master Catalog + Standard Templates — builds the nested category tree for
 * GET /boq-catalog/categories (and its /platform mount), same flat-load-then-assemble-in-memory
 * pattern App\Services\Boq\BoqTreeService already established for project/template trees (a
 * self-referential parent_id tree can be arbitrarily deep, so a fixed eager-load chain would
 * silently truncate it).
 *
 * Deliberately bypasses the ambient BelongsToOrganization scope and queries explicitly instead
 * — this keeps "which organization's custom catalog to merge with the global one" an explicit,
 * testable parameter rather than depending on whatever TenantContext happens to be resolved for
 * the current request. $organizationId === null means "global/system catalog only" (used by the
 * /platform mount); a non-null id means "that organization's own custom items merged with the
 * global catalog" (used by the normal tenant mount).
 */
class BoqCatalogTreeService
{
    private const ROOT_KEY = 'root';

    /**
     * @return array{categories: array}
     */
    public function buildCatalogTree(?int $organizationId): array
    {
        $categoryQuery = BoqCatalogCategory::withoutGlobalScopes()->where('is_active', true);
        $itemQuery = BoqCatalogItem::withoutGlobalScopes()->where('is_active', true);

        if ($organizationId === null) {
            $categoryQuery->whereNull('organization_id');
            $itemQuery->whereNull('organization_id');
        } else {
            $categoryQuery->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'));
            $itemQuery->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'));
        }

        $categories = $categoryQuery->orderBy('sort_order')->get();
        $items = $itemQuery->orderBy('sort_order')->get();

        $itemsByCategory = $items->groupBy('category_id');
        $categoriesByParent = $categories->groupBy(fn (BoqCatalogCategory $c) => $c->parent_id ?? self::ROOT_KEY);

        return [
            'categories' => $this->buildCategoryNodes(self::ROOT_KEY, $categoriesByParent, $itemsByCategory),
        ];
    }

    /**
     * @param  Collection<int|string, Collection<int, BoqCatalogCategory>>  $categoriesByParent
     * @param  Collection<int|string, Collection<int, BoqCatalogItem>>  $itemsByCategory
     */
    private function buildCategoryNodes(int|string $parentKey, Collection $categoriesByParent, Collection $itemsByCategory): array
    {
        $children = $categoriesByParent->get($parentKey, collect());

        return $children->map(function (BoqCatalogCategory $category) use ($categoriesByParent, $itemsByCategory) {
            $categoryItems = $itemsByCategory->get($category->id, collect());

            return [
                'id' => $category->id,
                'organization_id' => $category->organization_id,
                'is_system' => $category->organization_id === null,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'name_en' => $category->name_en,
                'name_ar' => $category->name_ar,
                'sort_order' => $category->sort_order,
                'items' => BoqCatalogItemResource::collection($categoryItems->values())->resolve(),
                'children' => $this->buildCategoryNodes($category->id, $categoriesByParent, $itemsByCategory),
            ];
        })->values()->all();
    }

    /**
     * Alias-aware catalog item search for the catalog picker / template-item-add flow.
     * LOWER()+LIKE, not `ilike` — matches ClientController::index()'s established convention
     * since the test suite runs on sqlite (ilike is Postgres-only).
     *
     * @return Collection<int, BoqCatalogItem>
     */
    public function search(string $term, ?int $organizationId): Collection
    {
        $needle = '%'.mb_strtolower($term).'%';

        $matchingAliasItemIds = BoqCatalogItemAlias::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(alias_en) LIKE ?', [$needle])->orWhereRaw('LOWER(alias_ar) LIKE ?', [$needle]))
            ->pluck('catalog_item_id');

        $query = BoqCatalogItem::withoutGlobalScopes()
            ->where('is_active', true)
            ->where(function ($q) use ($needle, $matchingAliasItemIds) {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(name_en) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(name_ar) LIKE ?', [$needle])
                    ->orWhereIn('id', $matchingAliasItemIds);
            });

        if ($organizationId === null) {
            $query->whereNull('organization_id');
        } else {
            $query->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'));
        }

        return $query->orderBy('name')->limit(50)->get();
    }
}
