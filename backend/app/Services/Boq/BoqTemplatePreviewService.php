<?php

namespace App\Services\Boq;

use App\Models\BoqCatalogCategory;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use Illuminate\Support\Collection;

/**
 * BOQ Master Catalog + Standard Templates — merges one or more selected template versions
 * (templates, packages, room templates are all just BoqTemplate rows, so this is the single
 * composition path for all of them) into one reviewable tree. Pure function: it never writes to
 * the database — BoqTemplateCommitService is the only thing that creates rows, once the engineer
 * has reviewed and possibly edited this output.
 *
 * Merge-conflict rule: when the same catalog item is contributed by more than one selected
 * version with DIFFERENT default_quantity values, the merged row's quantity is left null and
 * flagged needs_review — deliberately not auto-resolved via max/sum/first-wins, so the engineer
 * reviews it explicitly. When every contributor agrees (or there's only one), that value is used
 * as-is.
 */
class BoqTemplatePreviewService
{
    private const ROOT_KEY = 'root';

    /**
     * @param  array<int, array{template_version_id: int, selected_optional_item_ids?: array<int, int>}>  $selections
     * @return array{categories: array}
     */
    public function preview(array $selections, ?int $organizationId): array
    {
        $versionIds = collect($selections)->pluck('template_version_id')->filter()->unique()->all();

        $versions = BoqTemplateVersion::query()
            ->with(['template', 'items.catalogItem.defaultUnit', 'items.defaultUnit'])
            ->whereIn('id', $versionIds)
            ->get()
            ->keyBy('id');

        $contributionsByCatalogItem = [];

        foreach ($selections as $selection) {
            $version = $versions->get($selection['template_version_id'] ?? null);

            if (! $version) {
                continue;
            }

            $selectedOptionalIds = $selection['selected_optional_item_ids'] ?? [];

            foreach ($version->items as $templateItem) {
                $included = $templateItem->is_required || in_array($templateItem->id, $selectedOptionalIds, true);

                if (! $included) {
                    continue;
                }

                $contributionsByCatalogItem[$templateItem->catalog_item_id][] = [
                    'template_item' => $templateItem,
                    'version' => $version,
                ];
            }
        }

        $itemsByCategoryId = [];

        foreach ($contributionsByCatalogItem as $contributions) {
            $firstItem = $contributions[0]['template_item'];
            $itemsByCategoryId[$firstItem->category_id][] = $this->mergeContributions($contributions);
        }

        $categoryQuery = BoqCatalogCategory::withoutGlobalScopes()->where('is_active', true);

        if ($organizationId === null) {
            $categoryQuery->whereNull('organization_id');
        } else {
            $categoryQuery->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'));
        }

        $categories = $categoryQuery->orderBy('sort_order')->get();
        $categoriesByParent = $categories->groupBy(fn (BoqCatalogCategory $c) => $c->parent_id ?? self::ROOT_KEY);

        return [
            'categories' => $this->buildCategoryNodes(self::ROOT_KEY, $categoriesByParent, $itemsByCategoryId),
        ];
    }

    /**
     * @param  array<int, array{template_item: BoqTemplateItem, version: BoqTemplateVersion}>  $contributions
     */
    private function mergeContributions(array $contributions): array
    {
        $first = $contributions[0]['template_item'];

        $distinctQuantities = collect($contributions)
            ->map(fn ($c) => $c['template_item']->default_quantity)
            ->unique();

        $needsReview = $distinctQuantities->count() > 1;
        $isRequired = collect($contributions)->contains(fn ($c) => $c['template_item']->is_required);
        $unit = $first->defaultUnit ?? $first->catalogItem?->defaultUnit;

        return [
            'catalog_item_id' => $first->catalog_item_id,
            'name' => $first->catalogItem?->localizedName(null),
            'name_en' => $first->catalogItem?->name_en,
            'name_ar' => $first->catalogItem?->name_ar,
            'default_unit' => $unit ? ['id' => $unit->id, 'code' => $unit->code, 'name_en' => $unit->name_en, 'name_ar' => $unit->name_ar] : null,
            'quantity' => $needsReview ? null : $first->default_quantity,
            'quantity_source' => $first->quantity_source,
            'is_required' => $isRequired,
            'suggested_material_unit_cost' => $first->material_unit_cost ?? $first->catalogItem?->default_material_unit_cost,
            'suggested_labor_unit_cost' => $first->labor_unit_cost ?? $first->catalogItem?->default_labor_unit_cost,
            'suggested_other_unit_cost' => $first->other_unit_cost ?? $first->catalogItem?->default_other_unit_cost,
            'suggested_client_unit_price' => $first->client_unit_price ?? $first->catalogItem?->default_client_unit_price,
            'needs_review' => $needsReview,
            'contributed_by' => collect($contributions)->map(fn ($c) => [
                'template_id' => $c['version']->template_id,
                'template_name' => $c['version']->template?->name,
                'template_version_id' => $c['version']->id,
                'template_item_id' => $c['template_item']->id,
                'default_quantity' => $c['template_item']->default_quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int|string, Collection<int, BoqCatalogCategory>>  $categoriesByParent
     * @param  array<int, array<int, array>>  $itemsByCategoryId
     */
    private function buildCategoryNodes(int|string $parentKey, Collection $categoriesByParent, array $itemsByCategoryId): array
    {
        $children = $categoriesByParent->get($parentKey, collect());
        $nodes = [];

        foreach ($children as $category) {
            $childNodes = $this->buildCategoryNodes($category->id, $categoriesByParent, $itemsByCategoryId);
            $items = $itemsByCategoryId[$category->id] ?? [];

            // Prune branches with no items of their own and no descendant with items — the
            // preview should only show categories relevant to what was actually selected.
            if (empty($items) && empty($childNodes)) {
                continue;
            }

            $nodes[] = [
                'id' => $category->id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'name_en' => $category->name_en,
                'name_ar' => $category->name_ar,
                'items' => $items,
                'children' => $childNodes,
            ];
        }

        return $nodes;
    }
}
