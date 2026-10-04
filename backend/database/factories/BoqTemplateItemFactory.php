<?php

namespace Database\Factories;

use App\Models\BoqCatalogItem;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqTemplateItem>
 */
class BoqTemplateItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_version_id' => BoqTemplateVersion::factory(),
            'catalog_item_id' => BoqCatalogItem::factory(),
            // Resolve category_id from the (possibly newly created) catalog item so the two
            // never disagree, same rationale as BoqCatalogItemFactory resolving organization_id
            // from its category.
            'category_id' => function (array $attributes) {
                return BoqCatalogItem::find($attributes['catalog_item_id'])?->category_id;
            },
            'default_unit_id' => null,
            'default_quantity' => fake()->randomFloat(2, 1, 50),
            'quantity_formula' => null,
            'quantity_source' => BoqTemplateItem::SOURCE_FIXED_DEFAULT,
            'is_required' => true,
            'is_optional' => false,
            'is_enabled_by_default' => true,
            'material_unit_cost' => fake()->randomFloat(2, 50, 2000),
            'labor_unit_cost' => fake()->randomFloat(2, 20, 800),
            'other_unit_cost' => fake()->randomFloat(2, 0, 200),
            'client_unit_price' => fake()->randomFloat(2, 100, 3500),
            'notes' => null,
            'sort_order' => 0,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn () => [
            'is_required' => false,
            'is_optional' => true,
            'quantity_source' => BoqTemplateItem::SOURCE_OPTIONAL,
        ]);
    }
}
