<?php

namespace App\Services\Boq;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\BoqTemplateCategory;
use App\Models\BoqTemplateItem;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Apply template to project" (PROJECT_CONTEXT.md Sprint 2 "Templates"): copies a
 * boq_template_categories subtree (the given root category, its template items, and every
 * nested child template category/item recursively) into a project's boq_categories/boq_items
 * as brand-new rows. This is a COPY, never a reference — editing the cloned project BOQ must
 * never mutate the master template, which is the entire reason templates were split into
 * separate organization-scoped tables instead of reusing boq_categories/boq_items directly.
 *
 * The whole clone runs inside one DB transaction: a template subtree can be arbitrarily deep,
 * so a failure partway through (e.g. a DB constraint error on row 40 of 200) must not leave
 * the project with a half-copied, structurally-broken category tree.
 */
class BoqTemplateCloner
{
    public function applyToProject(BoqTemplateCategory $templateRoot, Project $project): BoqCategory
    {
        // Load the whole organization's template tree flat (two queries total, regardless of
        // depth) rather than lazily traversing ->children/->templateItems relations node by
        // node, same rationale as BoqTreeService.
        $organizationId = $templateRoot->organization_id;

        $allCategories = BoqTemplateCategory::query()->where('organization_id', $organizationId)->get();
        $allItems = BoqTemplateItem::query()->where('organization_id', $organizationId)->get();

        $categoriesByParent = $allCategories->groupBy('parent_id');
        $itemsByCategory = $allItems->groupBy('category_id');

        return DB::transaction(function () use ($templateRoot, $project, $categoriesByParent, $itemsByCategory) {
            return $this->cloneCategory($templateRoot, $project->id, null, $categoriesByParent, $itemsByCategory);
        });
    }

    /**
     * @param  Collection<int|string, Collection<int, BoqTemplateCategory>>  $categoriesByParent
     * @param  Collection<int|string, Collection<int, BoqTemplateItem>>  $itemsByCategory
     */
    private function cloneCategory(
        BoqTemplateCategory $templateCategory,
        int $projectId,
        ?int $parentId,
        Collection $categoriesByParent,
        Collection $itemsByCategory,
    ): BoqCategory {
        $category = BoqCategory::create([
            'project_id' => $projectId,
            'parent_id' => $parentId,
            'name' => $templateCategory->name,
            'sort_order' => $templateCategory->sort_order,
        ]);

        foreach ($itemsByCategory->get($templateCategory->id, collect()) as $templateItem) {
            /** @var BoqTemplateItem $templateItem */
            BoqItem::create([
                'project_id' => $projectId,
                'category_id' => $category->id,
                // Templates carry no room binding (a template line isn't tied to any specific
                // project's rooms — see BoqTemplateItem's docblock) and no quantity (the
                // office template prices a unit rate, not a project-specific measured
                // quantity); both start at their column defaults and are filled in by the
                // designer once the item lives in the actual project BOQ.
                'room_id' => null,
                'name' => $templateItem->name,
                'description' => $templateItem->description,
                'unit' => $templateItem->unit,
                'material_unit_cost' => $templateItem->material_unit_cost,
                'labor_unit_cost' => $templateItem->labor_unit_cost,
                'other_unit_cost' => $templateItem->other_unit_cost,
                'client_unit_price' => $templateItem->client_unit_price,
                'notes' => $templateItem->notes,
                'sort_order' => $templateItem->sort_order,
            ]);
        }

        foreach ($categoriesByParent->get($templateCategory->id, collect()) as $childTemplateCategory) {
            $this->cloneCategory($childTemplateCategory, $projectId, $category->id, $categoriesByParent, $itemsByCategory);
        }

        return $category;
    }
}
