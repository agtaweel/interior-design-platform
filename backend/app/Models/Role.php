<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * organization_id is nullable: null means a global template role (Owner, Admin, Designer,
 * Site Staff, ...); set means a custom role scoped to that organization.
 */
#[Fillable(['organization_id', 'name', 'permissions_json'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'permissions_json' => 'array',
        ];
    }

    public function organizationMembers(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }
}
