<?php

/**
 * BOQ Master Catalog + Standard Templates — the fixed, small set of measurement units used
 * across the whole catalog. Keyed by `code` (also the lookup key BoqMasterCatalogSeeder and
 * LegacyTemplateMigrator::resolveUnitId() match against, case-insensitively).
 */
return [
    ['code' => 'm2', 'name_en' => 'Square Meter', 'name_ar' => 'متر مربع'],
    ['code' => 'm', 'name_en' => 'Linear Meter', 'name_ar' => 'متر طولي'],
    ['code' => 'm3', 'name_en' => 'Cubic Meter', 'name_ar' => 'متر مكعب'],
    ['code' => 'pcs', 'name_en' => 'Piece', 'name_ar' => 'قطعة'],
    ['code' => 'point', 'name_en' => 'Point', 'name_ar' => 'نقطة'],
    ['code' => 'set', 'name_en' => 'Set', 'name_ar' => 'طقم'],
    ['code' => 'leaf', 'name_en' => 'Door Leaf', 'name_ar' => 'ضلفة'],
    ['code' => 'kg', 'name_en' => 'Kilogram', 'name_ar' => 'كيلوجرام'],
    ['code' => 'ton', 'name_en' => 'Ton', 'name_ar' => 'طن'],
    ['code' => 'ls', 'name_en' => 'Lump Sum', 'name_ar' => 'مقطوعية'],
    ['code' => 'hr', 'name_en' => 'Hour', 'name_ar' => 'ساعة'],
    ['code' => 'day', 'name_en' => 'Day', 'name_ar' => 'يوم'],
    ['code' => 'unit', 'name_en' => 'Unit', 'name_ar' => 'وحدة'],
];
