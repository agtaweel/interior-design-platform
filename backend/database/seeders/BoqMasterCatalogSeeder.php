<?php

namespace Database\Seeders;

use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Models\BoqUnit;
use Illuminate\Database\Seeder;

/**
 * BOQ Master Catalog + Standard Templates — seeds the system (organization_id = null) catalog
 * from database/seeders/data/boq/catalog.php. Idempotent via `updateOrCreate` keyed on the
 * data file's own stable string keys stored in `code`-less lookups (categories have no unique
 * code column, so matching is by organization_id=null + name + parent, which is unambiguous for
 * a hand-authored, non-duplicated seed file).
 *
 * Exposes `catalogItemIdsByKey(): array<string,int>` (populated after run()) so
 * BoqTemplateSeeder can resolve the same hand-authored keys to real ids without re-querying by
 * name.
 */
class BoqMasterCatalogSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $catalogItemIdsByKey = [];

    public function run(): void
    {
        $unitIdsByCode = BoqUnit::pluck('id', 'code');
        $data = require database_path('seeders/data/boq/catalog.php');
        $sortOrder = 0;

        foreach ($data as $topLevel) {
            $topCategory = BoqCatalogCategory::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => null, 'parent_id' => null, 'name' => $topLevel['name_en']],
                ['name_en' => $topLevel['name_en'], 'name_ar' => $topLevel['name_ar'], 'sort_order' => $sortOrder++, 'is_active' => true],
            );

            $subSortOrder = 0;

            foreach ($topLevel['subcategories'] as $subcategory) {
                $subCategory = BoqCatalogCategory::withoutGlobalScopes()->updateOrCreate(
                    ['organization_id' => null, 'parent_id' => $topCategory->id, 'name' => $subcategory['name_en']],
                    ['name_en' => $subcategory['name_en'], 'name_ar' => $subcategory['name_ar'], 'sort_order' => $subSortOrder++, 'is_active' => true],
                );

                $itemSortOrder = 0;

                foreach ($subcategory['items'] as $key => $item) {
                    $catalogItem = BoqCatalogItem::withoutGlobalScopes()->updateOrCreate(
                        ['organization_id' => null, 'category_id' => $subCategory->id, 'name' => $item['name_en']],
                        [
                            'name_en' => $item['name_en'],
                            'name_ar' => $item['name_ar'],
                            'default_unit_id' => $unitIdsByCode[$item['unit']] ?? null,
                            'default_material_unit_cost' => $item['material'],
                            'default_labor_unit_cost' => $item['labor'],
                            'default_other_unit_cost' => $item['other'],
                            'default_client_unit_price' => $item['client'],
                            'sort_order' => $itemSortOrder++,
                            'is_active' => true,
                        ],
                    );

                    $this->catalogItemIdsByKey[$key] = $catalogItem->id;
                }
            }
        }
    }

    /** @return array<string, int> */
    public function catalogItemIdsByKey(): array
    {
        return $this->catalogItemIdsByKey;
    }
}
