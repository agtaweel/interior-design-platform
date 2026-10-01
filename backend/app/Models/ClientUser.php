<?php

namespace App\Models;

use Database\Factories\ClientUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A marketplace client's login account (BRD v4 "Client Marketplace") — deliberately a separate
 * model/table from the staff `User`, not a flag on it. A `ClientUser` belongs to no organization
 * and never goes through `ResolveTenantContext`/`tenant` middleware; every `/client/...` route is
 * gated by `EnsureClientUser` instead (mirrors how `EnsurePlatformOwner` gates `/platform/...`
 * for the structurally similar "authenticated but not an org member" case).
 *
 * Reuses Sanctum's `HasApiTokens` exactly like `User` does — Sanctum's `personal_access_tokens`
 * table is polymorphic (tokenable_type/tokenable_id), so no second guard is needed; middleware
 * just checks `$request->user() instanceof ClientUser`.
 *
 * `clients()` is the link to this client's org-scoped `Client` contact rows (one per
 * organization they've engaged with via the marketplace) — see `Client::clientUser()` for the
 * inverse and the migration that added `clients.client_user_id` for the uniqueness constraint.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password'])]
class ClientUser extends Authenticatable
{
    /** @use HasFactory<ClientUserFactory> */
    use HasApiTokens, HasFactory;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
