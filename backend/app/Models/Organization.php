<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['name', 'legal_name', 'logo_url', 'phone', 'email', 'currency', 'timezone', 'settings_json'])]
class Organization extends Model implements HasMedia
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * Marketing photos for the public marketplace listing (BRD v4 "Client Marketplace") — the
     * first genuinely PUBLIC media collection in this codebase (contrast
     * Project::MEDIA_COLLECTIONS, which are always served through an authenticated or
     * token-scoped route). Served only via PublicOrganizationMediaController, which resolves
     * strictly through `$organization->media()` scoped to an `is_marketplace_listed` org and
     * this collection name — never a bare `Media::find()` — since Spatie's `media` table is one
     * polymorphic table also holding private Project design files.
     */
    public const MARKETPLACE_MEDIA_COLLECTIONS = ['portfolio'];

    public function registerMediaCollections(): void
    {
        foreach (self::MARKETPLACE_MEDIA_COLLECTIONS as $collection) {
            $this->addMediaCollection($collection);
        }
    }

    public function profile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    protected function casts(): array
    {
        return [
            'settings_json' => 'array',
        ];
    }

    public function organizationMembers(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * Users belonging to this organization, via the organization_members pivot.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function boqTemplateCategories(): HasMany
    {
        return $this->hasMany(BoqTemplateCategory::class);
    }

    public function boqTemplateItems(): HasMany
    {
        return $this->hasMany(BoqTemplateItem::class);
    }
}
