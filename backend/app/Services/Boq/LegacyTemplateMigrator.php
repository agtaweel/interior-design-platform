<?php

namespace App\Services\Boq;

use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Models\BoqTemplate;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use App\Models\BoqUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BOQ Master Catalog + Standard Templates — one-time transcription of every organization's
 * legacy flat template (boq_template_categories_legacy/boq_template_items_legacy, renamed out
 * of the way by the rename_legacy_boq_template_tables migration) into the new model: one
 * BoqTemplate header + one published BoqTemplateVersion per organization that had legacy data,
 * a BoqCatalogCategory for every legacy category (preserving the parent/child tree), and BOTH a
 * new BoqCatalogItem AND a BoqTemplateItem referencing it for every legacy item — so existing
 * organizations lose none of their template data across the replacement.
 *
 * Idempotent: an organization whose `LEGACY-{orgId}` template already exists is skipped, so
 * running this twice (e.g. a retried deploy) never double-imports. Invoked by the
 * migrate_legacy_boq_templates_data migration; also exposed via `php artisan
 * boq:migrate-legacy-templates --dry-run` for operator visibility before it actually writes.
 */
class LegacyTemplateMigrator
{
    /**
     * @return array<int, array<string, mixed>> one summary row per organization touched
     */
    public function run(bool $dryRun = false): array
    {
        $legacyCategories = DB::table('boq_template_categories_legacy')->get();
        $legacyItems = DB::table('boq_template_items_legacy')->get();

        $organizationIds = $legacyCategories->pluck('organization_id')->unique()->values();

        return $organizationIds
            ->map(fn ($organizationId) => $this->migrateOrganization((int) $organizationId, $legacyCategories, $legacyItems, $dryRun))
            ->all();
    }

    /**
     * @param  Collection<int, object>  $allLegacyCategories
     * @param  Collection<int, object>  $allLegacyItems
     */
    private function migrateOrganization(int $organizationId, Collection $allLegacyCategories, Collection $allLegacyItems, bool $dryRun): array
    {
        $code = 'LEGACY-'.$organizationId;

        $alreadyMigrated = BoqTemplate::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->exists();

        if ($alreadyMigrated) {
            return ['organization_id' => $organizationId, 'status' => 'skipped_already_migrated'];
        }

        $orgCategories = $allLegacyCategories->where('organization_id', $organizationId);

        if ($dryRun) {
            $categoryIds = $orgCategories->pluck('id');

            return [
                'organization_id' => $organizationId,
                'status' => 'would_migrate',
                'categories' => $orgCategories->count(),
                'items' => $allLegacyItems->whereIn('category_id', $categoryIds)->count(),
            ];
        }

        return DB::transaction(function () use ($organizationId, $code, $orgCategories, $allLegacyItems) {
            $template = BoqTemplate::create([
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => 'Imported Template',
                'template_type' => BoqTemplate::TYPE_CUSTOM,
                'is_system' => false,
            ]);

            $version = BoqTemplateVersion::create([
                'template_id' => $template->id,
                'version_number' => 1,
                'status' => BoqTemplateVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $template->update(['active_version_id' => $version->id]);

            $categoryIdMap = $this->migrateCategories($organizationId, $orgCategories);
            $itemCount = $this->migrateItems($organizationId, $version, $categoryIdMap, $allLegacyItems);

            Log::info("LegacyTemplateMigrator: migrated organization {$organizationId} — ".count($categoryIdMap)." categories, {$itemCount} items.");

            return [
                'organization_id' => $organizationId,
                'status' => 'migrated',
                'template_id' => $template->id,
                'categories' => count($categoryIdMap),
                'items' => $itemCount,
            ];
        });
    }

    /**
     * Legacy categories can nest (parent_id self-reference), so parents must be created before
     * their children. The legacy dataset is small (office-authored templates, not the master
     * catalog), so a simple "repeat until no more progress" resolution pass is sufficient —
     * no need for a real topological sort.
     *
     * @return array<int, int> legacy category id => new BoqCatalogCategory id
     */
    private function migrateCategories(int $organizationId, Collection $orgCategories): array
    {
        $categoryIdMap = [];
        $remaining = $orgCategories->keyBy('id');

        while ($remaining->isNotEmpty()) {
            $progressed = false;

            foreach ($remaining as $legacyId => $legacyCategory) {
                $legacyParentId = $legacyCategory->parent_id;

                if ($legacyParentId !== null && ! array_key_exists($legacyParentId, $categoryIdMap)) {
                    continue;
                }

                $newCategory = BoqCatalogCategory::create([
                    'organization_id' => $organizationId,
                    'parent_id' => $legacyParentId !== null ? $categoryIdMap[$legacyParentId] : null,
                    'name' => $legacyCategory->name,
                    'sort_order' => $legacyCategory->sort_order,
                ]);

                $categoryIdMap[$legacyId] = $newCategory->id;
                $remaining->forget($legacyId);
                $progressed = true;
            }

            if (! $progressed) {
                // A broken/cyclic parent_id reference in the legacy data — skip the remainder
                // rather than looping forever; log so it's visible, not silently dropped.
                Log::warning('LegacyTemplateMigrator: could not resolve parent chain for categories '.$remaining->keys()->implode(', ')." in organization {$organizationId}.");
                break;
            }
        }

        return $categoryIdMap;
    }

    /**
     * @param  array<int, int>  $categoryIdMap
     * @param  Collection<int, object>  $allLegacyItems
     */
    private function migrateItems(int $organizationId, BoqTemplateVersion $version, array $categoryIdMap, Collection $allLegacyItems): int
    {
        $items = $allLegacyItems->whereIn('category_id', array_keys($categoryIdMap));
        $count = 0;

        foreach ($items as $legacyItem) {
            $newCategoryId = $categoryIdMap[$legacyItem->category_id];
            $unitId = $this->resolveUnitId($legacyItem->unit);

            $catalogItem = BoqCatalogItem::create([
                'organization_id' => $organizationId,
                'category_id' => $newCategoryId,
                'name' => $legacyItem->name,
                'description' => $legacyItem->description,
                'default_unit_id' => $unitId,
                'default_material_unit_cost' => $legacyItem->material_unit_cost,
                'default_labor_unit_cost' => $legacyItem->labor_unit_cost,
                'default_other_unit_cost' => $legacyItem->other_unit_cost,
                'default_client_unit_price' => $legacyItem->client_unit_price,
                'sort_order' => $legacyItem->sort_order,
            ]);

            BoqTemplateItem::create([
                'template_version_id' => $version->id,
                'catalog_item_id' => $catalogItem->id,
                'category_id' => $newCategoryId,
                'default_unit_id' => $unitId,
                'quantity_source' => BoqTemplateItem::SOURCE_FIXED_DEFAULT,
                'is_required' => true,
                'material_unit_cost' => $legacyItem->material_unit_cost,
                'labor_unit_cost' => $legacyItem->labor_unit_cost,
                'other_unit_cost' => $legacyItem->other_unit_cost,
                'client_unit_price' => $legacyItem->client_unit_price,
                'notes' => $legacyItem->notes,
                'sort_order' => $legacyItem->sort_order,
            ]);

            $count++;
        }

        return $count;
    }

    private function resolveUnitId(?string $legacyUnit): ?int
    {
        if (! $legacyUnit) {
            return null;
        }

        return BoqUnit::whereRaw('LOWER(code) = ?', [mb_strtolower($legacyUnit)])->value('id');
    }
}
