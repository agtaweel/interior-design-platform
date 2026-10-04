<?php

namespace Database\Factories;

use App\Models\BoqCatalogItem;
use App\Models\BoqCatalogItemAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqCatalogItemAlias>
 */
class BoqCatalogItemAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'catalog_item_id' => BoqCatalogItem::factory(),
            'alias_en' => fake()->word(),
            'alias_ar' => null,
        ];
    }
}
