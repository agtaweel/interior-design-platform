<?php

namespace App\Models;

use Database\Factories\BoqUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BOQ Master Catalog + Standard Templates — a global (not organization-scoped) reference unit
 * (m2, lm, pcs, point, ...), bilingual. See the migration's docblock for why this never becomes
 * a breaking FK on the existing `boq_items.unit` plain-string column.
 */
#[Fillable(['code', 'name_en', 'name_ar', 'sort_order', 'is_active'])]
class BoqUnit extends Model
{
    /** @use HasFactory<BoqUnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function localizedName(?string $locale): string
    {
        return $locale === 'ar' ? $this->name_ar : $this->name_en;
    }
}
