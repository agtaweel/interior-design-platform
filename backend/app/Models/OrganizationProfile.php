<?php

namespace App\Models;

use Database\Factories\OrganizationProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The public marketplace listing for an organization — see the migration's docblock for why
 * this is a separate 1:1 table rather than columns on `Organization` itself.
 */
#[Fillable(['organization_id', 'description', 'services_offered', 'service_area', 'is_marketplace_listed'])]
class OrganizationProfile extends Model
{
    /** @use HasFactory<OrganizationProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'services_offered' => 'array',
            'is_marketplace_listed' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
