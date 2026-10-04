<?php

namespace Database\Seeders;

use App\Models\BoqUnit;
use Illuminate\Database\Seeder;

/**
 * BOQ Master Catalog + Standard Templates — the fixed unit list (database/seeders/data/boq/units.php).
 */
class BoqUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = require database_path('seeders/data/boq/units.php');

        foreach ($units as $sortOrder => $unit) {
            BoqUnit::query()->updateOrCreate(
                ['code' => $unit['code']],
                ['name_en' => $unit['name_en'], 'name_ar' => $unit['name_ar'], 'sort_order' => $sortOrder, 'is_active' => true],
            );
        }
    }
}
