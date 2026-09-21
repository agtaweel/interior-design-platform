<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BoqTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Directly organization-scoped, NOT project-scoped — same convention as
 * BoqTemplateCategory. Deliberately has no quantity/room_id: a template line isn't tied to
 * any specific project's rooms (see PROJECT_CONTEXT.md Sprint 2 "Templates").
 */
#[Fillable([
    'organization_id', 'category_id', 'name', 'description', 'unit',
    'material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'client_unit_price',
    'notes', 'sort_order',
])]
class BoqTemplateItem extends Model
{
    /** @use HasFactory<BoqTemplateItemFactory> */
    use HasFactory, BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'material_unit_cost' => 'decimal:2',
            'labor_unit_cost' => 'decimal:2',
            'other_unit_cost' => 'decimal:2',
            'client_unit_price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BoqTemplateCategory::class, 'category_id');
    }
}
