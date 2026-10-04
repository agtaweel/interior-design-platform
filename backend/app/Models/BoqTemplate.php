<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BoqTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BOQ Master Catalog + Standard Templates — a named, versioned template header. Rooms
 * (`template_type = ROOM`) and packages (`template_type = PACKAGE`) are ordinary rows in this
 * same table, not separate models — see the migration's docblock. `organization_id` nullable
 * via BelongsToOrganization: null = system template (ships with the app), non-null = an
 * organization's own private template.
 */
#[Fillable([
    'organization_id', 'code', 'name', 'name_en', 'name_ar',
    'description', 'description_en', 'description_ar', 'template_type', 'project_type',
    'finishing_level', 'is_system', 'is_active', 'sort_order', 'active_version_id',
    'created_by', 'updated_by',
])]
class BoqTemplate extends Model
{
    /** @use HasFactory<BoqTemplateFactory> */
    use HasFactory, BelongsToOrganization, SoftDeletes, Auditable;

    public const TYPE_FULL_FINISHING = 'FULL_FINISHING';

    public const TYPE_RENOVATION = 'RENOVATION';

    public const TYPE_PARTIAL_FINISHING = 'PARTIAL_FINISHING';

    public const TYPE_ROOM = 'ROOM';

    public const TYPE_TRADE = 'TRADE';

    public const TYPE_PACKAGE = 'PACKAGE';

    public const TYPE_PREMIUM = 'PREMIUM';

    public const TYPE_LUXURY = 'LUXURY';

    public const TYPE_CUSTOM = 'CUSTOM';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BoqTemplateVersion::class, 'template_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(BoqTemplateVersion::class, 'active_version_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(BoqTemplateApplication::class, 'template_id');
    }

    public function localizedName(?string $locale): string
    {
        return ($locale === 'ar' ? $this->name_ar : $this->name_en) ?: $this->name;
    }
}
