<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'phone', 'email', 'address', 'notes'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, BelongsToOrganization, Auditable;

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
