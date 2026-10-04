<?php

namespace App\Services\Boq;

use App\Models\BoqTemplate;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use Illuminate\Support\Facades\DB;

/**
 * BOQ Master Catalog + Standard Templates — version lifecycle. See BoqTemplateVersion's docblock
 * for why each version is a full, independent copy of its items rather than a diff: that
 * independence is what this class implements (createDraftVersion optionally deep-copies
 * $copyFrom's items) and what publish() protects (demoting any previously-published sibling
 * rather than mutating it).
 */
class BoqTemplateVersionService
{
    public function createDraftVersion(BoqTemplate $template, ?BoqTemplateVersion $copyFrom = null, ?int $createdBy = null): BoqTemplateVersion
    {
        return DB::transaction(function () use ($template, $copyFrom, $createdBy) {
            $nextVersionNumber = ((int) $template->versions()->max('version_number')) + 1;

            $version = BoqTemplateVersion::create([
                'template_id' => $template->id,
                'version_number' => $nextVersionNumber,
                'status' => BoqTemplateVersion::STATUS_DRAFT,
                'created_by' => $createdBy,
            ]);

            if ($copyFrom) {
                foreach ($copyFrom->items as $item) {
                    BoqTemplateItem::create([
                        'template_version_id' => $version->id,
                        'catalog_item_id' => $item->catalog_item_id,
                        'category_id' => $item->category_id,
                        'default_unit_id' => $item->default_unit_id,
                        'default_quantity' => $item->default_quantity,
                        'quantity_formula' => $item->quantity_formula,
                        'quantity_source' => $item->quantity_source,
                        'is_required' => $item->is_required,
                        'is_optional' => $item->is_optional,
                        'is_enabled_by_default' => $item->is_enabled_by_default,
                        'material_unit_cost' => $item->material_unit_cost,
                        'labor_unit_cost' => $item->labor_unit_cost,
                        'other_unit_cost' => $item->other_unit_cost,
                        'client_unit_price' => $item->client_unit_price,
                        'notes' => $item->notes,
                        'sort_order' => $item->sort_order,
                    ]);
                }
            }

            return $version->fresh();
        });
    }

    /**
     * Publishing is exclusive per template: any OTHER published version of the same template is
     * demoted to archived (never mutated beyond its status — its items stay exactly as they
     * were, so a project that already applied it keeps a perfectly accurate historical record).
     */
    public function publish(BoqTemplateVersion $version): void
    {
        DB::transaction(function () use ($version) {
            BoqTemplateVersion::query()
                ->where('template_id', $version->template_id)
                ->where('status', BoqTemplateVersion::STATUS_PUBLISHED)
                ->where('id', '!=', $version->id)
                ->update(['status' => BoqTemplateVersion::STATUS_ARCHIVED]);

            $version->forceFill(['status' => BoqTemplateVersion::STATUS_PUBLISHED, 'published_at' => now()])->save();
            $version->template->forceFill(['active_version_id' => $version->id])->save();
        });
    }
}
