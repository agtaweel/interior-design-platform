<?php

namespace App\Services\Boq;

use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\BoqTemplateApplication;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BOQ Master Catalog + Standard Templates — commits the engineer-reviewed/edited output of
 * BoqTemplatePreviewService into real, independent BoqItem rows. Replaces the old
 * BoqTemplateCloner (deleted): same end goal ("get template content into the project BOQ"), but
 * now source-tracked and conflict-reviewed rather than a blind copy.
 *
 * Project categories are matched/created by walking the catalog category's own parent chain and
 * firstOrCreate-ing a same-named BoqCategory under the project at each level — so re-applying a
 * template, or applying a second template that shares a category, reuses the existing project
 * category instead of duplicating it. Once created, a BoqItem is a fully independent row: editing
 * it never touches the catalog or template it came from, and future template edits never touch it
 * back (enforced simply by never re-reading source_* columns for anything but display/reporting).
 */
class BoqTemplateCommitService
{
    /**
     * @param  array<int, array{catalog_item_id: int, category_id?: int, name?: string, unit?: string, quantity: float|string, material_unit_cost?: float|string|null, labor_unit_cost?: float|string|null, other_unit_cost?: float|string|null, client_unit_price?: float|string|null, source_template_id?: int|null, source_template_version_id?: int|null, source_template_item_id?: int|null, sort_order?: int}>  $resolvedItems
     * @return Collection<int, BoqItem>
     */
    public function commit(Project $project, array $resolvedItems, User $actor): Collection
    {
        return DB::transaction(function () use ($project, $resolvedItems, $actor) {
            $categoryMemo = [];
            $applicationCounts = [];
            $createdItems = collect();

            foreach ($resolvedItems as $row) {
                $catalogItem = BoqCatalogItem::withoutGlobalScopes()->with('defaultUnit')->find($row['catalog_item_id']);
                $catalogCategoryId = $row['category_id'] ?? $catalogItem?->category_id;

                $boqCategoryId = $catalogCategoryId
                    ? $this->resolveProjectCategory($project, (int) $catalogCategoryId, $categoryMemo)
                    : null;

                $item = BoqItem::create([
                    'project_id' => $project->id,
                    'category_id' => $boqCategoryId,
                    'room_id' => null,
                    'name' => $row['name'] ?? $catalogItem?->name ?? 'Untitled item',
                    'description' => $catalogItem?->description,
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'] ?? $catalogItem?->defaultUnit?->code,
                    'material_unit_cost' => $row['material_unit_cost'] ?? $catalogItem?->default_material_unit_cost ?? 0,
                    'labor_unit_cost' => $row['labor_unit_cost'] ?? $catalogItem?->default_labor_unit_cost ?? 0,
                    'other_unit_cost' => $row['other_unit_cost'] ?? $catalogItem?->default_other_unit_cost ?? 0,
                    'client_unit_price' => $row['client_unit_price'] ?? $catalogItem?->default_client_unit_price ?? 0,
                    'sort_order' => $row['sort_order'] ?? 0,
                    'source_template_id' => $row['source_template_id'] ?? null,
                    'source_template_version_id' => $row['source_template_version_id'] ?? null,
                    'source_template_item_id' => $row['source_template_item_id'] ?? null,
                    'source_catalog_item_id' => $row['catalog_item_id'],
                ]);

                $createdItems->push($item);

                if (! empty($row['source_template_version_id'])) {
                    $versionId = $row['source_template_version_id'];
                    $applicationCounts[$versionId] ??= ['template_id' => $row['source_template_id'] ?? null, 'count' => 0];
                    $applicationCounts[$versionId]['count']++;
                }
            }

            foreach ($applicationCounts as $versionId => $data) {
                BoqTemplateApplication::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'template_id' => $data['template_id'],
                    'template_version_id' => $versionId,
                    'applied_by' => $actor->id,
                    'item_count' => $data['count'],
                ]);
            }

            return $createdItems;
        });
    }

    /** @param  array<int, int>  $memo  catalog_category_id => boq_categories.id, scoped to this commit() call only */
    private function resolveProjectCategory(Project $project, int $catalogCategoryId, array &$memo): int
    {
        if (isset($memo[$catalogCategoryId])) {
            return $memo[$catalogCategoryId];
        }

        $catalogCategory = BoqCatalogCategory::withoutGlobalScopes()->findOrFail($catalogCategoryId);

        $parentBoqCategoryId = $catalogCategory->parent_id
            ? $this->resolveProjectCategory($project, $catalogCategory->parent_id, $memo)
            : null;

        $boqCategory = BoqCategory::firstOrCreate([
            'project_id' => $project->id,
            'parent_id' => $parentBoqCategoryId,
            'name' => $catalogCategory->name,
        ]);

        return $memo[$catalogCategoryId] = $boqCategory->id;
    }
}
