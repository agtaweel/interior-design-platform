<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'client_user_id', 'name', 'phone', 'email', 'address', 'notes'])]
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

    /**
     * The marketplace login account this contact record is linked to, if the client signed up
     * (BRD v4 "Client Marketplace") — null for clients created the traditional staff-only way.
     * See the `clients.client_user_id` migration for the uniqueness constraint (at most one
     * `Client` row per organization per `ClientUser`).
     */
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }
}
