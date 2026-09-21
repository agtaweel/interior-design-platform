<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as ProjectMember/ProjectService.
 *
 * Deliberately NOT Auditable: a room is purely structural (name/area/ordering), not a
 * commercial record — unlike BoqItem, which carries unit costs and is audited.
 */
#[Fillable(['project_id', 'name', 'area_m2', 'sort_order'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'area_m2' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class);
    }
}
