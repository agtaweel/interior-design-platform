<?php

namespace Database\Seeders;

use App\Models\BoqCatalogItem;
use App\Models\BoqTemplate;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use Illuminate\Database\Seeder;

/**
 * BOQ Master Catalog + Standard Templates — seeds every system (organization_id = null)
 * template from database/seeders/data/boq/templates/*.php: the three flagship Full Apartment
 * Finishing templates (fully populated), the 8 lighter named templates, the 12 room templates,
 * and the 4 packages (per the locked content-depth decision). Runs BoqMasterCatalogSeeder first
 * so catalog item keys resolve to real ids — safe to re-run (that seeder is itself idempotent).
 *
 * Re-running THIS seeder replaces each system template's version/items wholesale (delete +
 * recreate) so it always exactly reflects the current data file — this is seed/demo data, not a
 * production migration, so it is not safe to re-run against an environment where real projects
 * have already applied these templates (BoqTemplateApplication/BoqItem's source_* pointers would
 * null out via their nullOnDelete FKs, though the project's own BoqItem rows are never touched).
 */
class BoqTemplateSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $catalogItemIdsByKey = [];

    public function run(): void
    {
        $catalogSeeder = new BoqMasterCatalogSeeder();
        $catalogSeeder->run();
        $this->catalogItemIdsByKey = $catalogSeeder->catalogItemIdsByKey();

        $this->seedFullApartmentFinishing();
        $this->seedOtherTemplates();
    }

    private function seedOtherTemplates(): void
    {
        $data = require database_path('seeders/data/boq/templates/other_templates.php');

        foreach ($data['named_templates'] as $template) {
            $this->createTemplate($template, $template['items'], $template['type']);
        }

        foreach ($data['room_templates'] as $template) {
            $this->createTemplate($template, $template['items'], BoqTemplate::TYPE_ROOM);
        }

        foreach ($data['packages'] as $template) {
            $this->createTemplate($template, $template['items'], BoqTemplate::TYPE_PACKAGE);
        }
    }

    private function seedFullApartmentFinishing(): void
    {
        $data = require database_path('seeders/data/boq/templates/full_apartment_finishing.php');

        $standardItems = $data['standard']['items'];
        $this->createTemplate($data['standard'], $standardItems);

        $premiumItems = $this->applyDelta($standardItems, $data['premium']);
        $this->createTemplate($data['premium'], $premiumItems);

        $luxuryItems = $this->applyDelta($premiumItems, $data['luxury']);
        $this->createTemplate($data['luxury'], $luxuryItems);
    }

    /** @param  array<string, array{quantity: float|null, required: bool}>  $baseItems */
    private function applyDelta(array $baseItems, array $tierData): array
    {
        foreach ($tierData['remove'] ?? [] as $key) {
            unset($baseItems[$key]);
        }

        foreach ($tierData['add_or_override'] ?? [] as $key => $spec) {
            $baseItems[$key] = $spec;
        }

        return $baseItems;
    }

    /**
     * @param  array{code: string, name_en: string, name_ar: string, finishing_level?: ?string}  $meta
     * @param  array<string, array{quantity: float|null, required: bool}>  $items
     */
    private function createTemplate(array $meta, array $items, string $type = BoqTemplate::TYPE_FULL_FINISHING, ?string $projectType = null): BoqTemplate
    {
        $template = BoqTemplate::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => null, 'code' => $meta['code']],
            [
                'name' => $meta['name_en'],
                'name_en' => $meta['name_en'],
                'name_ar' => $meta['name_ar'],
                'template_type' => $type,
                'project_type' => $projectType,
                'finishing_level' => $meta['finishing_level'] ?? null,
                'is_system' => true,
                'is_active' => true,
            ],
        );

        BoqTemplateItem::whereIn('template_version_id', $template->versions()->pluck('id'))->delete();
        $template->versions()->delete();

        $version = BoqTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => BoqTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $template->update(['active_version_id' => $version->id]);

        $sortOrder = 0;

        foreach ($items as $key => $spec) {
            $catalogItemId = $this->catalogItemIdsByKey[$key] ?? null;

            if (! $catalogItemId) {
                throw new \RuntimeException("Unknown catalog item key '{$key}' referenced by template '{$meta['code']}'.");
            }

            $catalogItem = BoqCatalogItem::withoutGlobalScopes()->findOrFail($catalogItemId);
            $required = $spec['required'];

            BoqTemplateItem::create([
                'template_version_id' => $version->id,
                'catalog_item_id' => $catalogItemId,
                'category_id' => $catalogItem->category_id,
                'default_quantity' => $spec['quantity'],
                'quantity_source' => $required ? BoqTemplateItem::SOURCE_FIXED_DEFAULT : BoqTemplateItem::SOURCE_OPTIONAL,
                'is_required' => $required,
                'is_optional' => ! $required,
                'is_enabled_by_default' => true,
                'material_unit_cost' => $catalogItem->default_material_unit_cost,
                'labor_unit_cost' => $catalogItem->default_labor_unit_cost,
                'other_unit_cost' => $catalogItem->default_other_unit_cost,
                'client_unit_price' => $catalogItem->default_client_unit_price,
                'sort_order' => $sortOrder++,
            ]);
        }

        return $template;
    }
}
