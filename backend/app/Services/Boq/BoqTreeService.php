<?php

namespace App\Services\Boq;

use App\Http\Resources\BoqItemResource;
use App\Http\Resources\BoqTemplateItemResource;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\BoqTemplateCategory;
use App\Models\BoqTemplateItem;
use App\Models\Project;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Builds the nested category tree (+ room list + grand total) for GET /projects/{id}/boq, and
 * the equivalent nested tree for GET /boq-templates/categories.
 *
 * Both trees are built by loading every row for the project/organization FLAT (one query per
 * table) and assembling parent/child relationships in memory, rather than eager-loading
 * `children.children.children...`. `boq_categories.parent_id` (and
 * `boq_template_categories.parent_id`) is self-referential to an unbounded depth, so a fixed
 * eager-load chain would silently truncate deeper trees; this approach handles any depth with
 * exactly a constant number of queries.
 *
 * Subtotal scope decision (PROJECT_CONTEXT.md Sprint 2 "Calculations" says only "category and
 * room subtotals roll up from items", without specifying whether a parent category's subtotal
 * includes its descendant categories' items): this implementation sums only the items directly
 * assigned to that category (`boq_items.category_id = category.id`), NOT items belonging to
 * child categories. Each nested child category shows its own subtotal in the tree, and the
 * project-level `grand_total` already sums every non-archived item regardless of nesting, so
 * nothing is hidden — this just avoids double-counting a nested item in both its own category
 * and every ancestor. Revisit if the BOQ Builder UI (S07, frontend sprint) wants
 * children-inclusive parent subtotals.
 */
class BoqTreeService
{
    /** Array-key stand-in for a null parent_id (root level) — see buildProjectTree()'s note. */
    private const ROOT_KEY = 'root';

    /**
     * @return array{categories: array, rooms: array, grand_total: array{direct_cost: string, client_total: string}, include_archived: bool}
     */
    public function buildProjectTree(Project $project, bool $includeArchived): array
    {
        $categories = BoqCategory::query()->where('project_id', $project->id)->orderBy('sort_order')->get();

        $itemsQuery = $project->boqItems()->orderBy('sort_order');

        if (! $includeArchived) {
            $itemsQuery->whereNull('archived_at');
        }

        $items = $itemsQuery->get();
        $itemsByCategory = $items->groupBy('category_id');
        $itemsByRoom = $items->whereNotNull('room_id')->groupBy('room_id');

        // Note: groupBy('parent_id') casts a null parent_id to the "" array key (PHP arrays
        // can't have a literal null key), so the root level is looked up under self::ROOT_KEY
        // below rather than under `null` itself.
        $categoriesByParent = $categories->groupBy(fn (BoqCategory $c) => $c->parent_id ?? self::ROOT_KEY);

        $rooms = Room::query()->where('project_id', $project->id)->orderBy('sort_order')->get();

        return [
            'categories' => $this->buildCategoryNodes(self::ROOT_KEY, $categoriesByParent, $itemsByCategory),
            'rooms' => $rooms->map(function (Room $room) use ($itemsByRoom) {
                $roomItems = $itemsByRoom->get($room->id, collect());

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'area_m2' => $room->area_m2,
                    'sort_order' => $room->sort_order,
                    'subtotal' => $this->subtotal($roomItems),
                ];
            })->values()->all(),
            'grand_total' => $this->subtotal($items),
            'include_archived' => $includeArchived,
        ];
    }

    /**
     * @param  Collection<int|string, Collection<int, BoqCategory>>  $categoriesByParent
     * @param  Collection<int|string, Collection<int, BoqItem>>  $itemsByCategory
     */
    private function buildCategoryNodes(int|string $parentKey, Collection $categoriesByParent, Collection $itemsByCategory): array
    {
        $children = $categoriesByParent->get($parentKey, collect());

        return $children->map(function (BoqCategory $category) use ($categoriesByParent, $itemsByCategory) {
            $categoryItems = $itemsByCategory->get($category->id, collect());

            return [
                'id' => $category->id,
                'project_id' => $category->project_id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
                'subtotal' => $this->subtotal($categoryItems),
                'items' => BoqItemResource::collection($categoryItems->values())->resolve(),
                // $category->id is always a real int (never null), so it round-trips through
                // the same groupBy key space without needing the ROOT_KEY normalization.
                'children' => $this->buildCategoryNodes($category->id, $categoriesByParent, $itemsByCategory),
            ];
        })->values()->all();
    }

    /**
     * @return array{organization_id: int, categories: array}
     */
    public function buildTemplateTree(int $organizationId): array
    {
        $categories = BoqTemplateCategory::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->get();

        $items = BoqTemplateItem::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->get();

        $itemsByCategory = $items->groupBy('category_id');
        $categoriesByParent = $categories->groupBy(fn (BoqTemplateCategory $c) => $c->parent_id ?? self::ROOT_KEY);

        return [
            'organization_id' => $organizationId,
            'categories' => $this->buildTemplateCategoryNodes(self::ROOT_KEY, $categoriesByParent, $itemsByCategory),
        ];
    }

    private function buildTemplateCategoryNodes(int|string $parentKey, Collection $categoriesByParent, Collection $itemsByCategory): array
    {
        $children = $categoriesByParent->get($parentKey, collect());

        return $children->map(function (BoqTemplateCategory $category) use ($categoriesByParent, $itemsByCategory) {
            $categoryItems = $itemsByCategory->get($category->id, collect());

            return [
                'id' => $category->id,
                'organization_id' => $category->organization_id,
                'parent_id' => $category->parent_id,
                'name' => $category->name,
                'sort_order' => $category->sort_order,
                'items' => BoqTemplateItemResource::collection($categoryItems->values())->resolve(),
                'children' => $this->buildTemplateCategoryNodes($category->id, $categoriesByParent, $itemsByCategory),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, BoqItem>  $items
     * @return array{direct_cost: string, client_total: string}
     */
    private function subtotal(Collection $items): array
    {
        return [
            'direct_cost' => BoqMoney::sumAccessor($items, 'direct_cost'),
            'client_total' => BoqMoney::sumAccessor($items, 'client_total'),
        ];
    }
}
