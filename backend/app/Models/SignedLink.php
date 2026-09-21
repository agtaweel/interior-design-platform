<?php

namespace App\Models;

use Database\Factories\SignedLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped via BelongsToOrganization: rows here are looked up by public,
 * unauthenticated requests (no TenantContext exists yet at that point) using the token hash as
 * the sole key. Callers should put organization_id inside payload_json if they need it after
 * verification. See App\Support\PublicLinks\SignedLinkService for the intended API.
 */
#[Fillable([
    'purpose', 'token_hash', 'payload_json', 'expires_at', 'revoked_at',
    'last_used_at', 'use_count', 'created_by',
])]
class SignedLink extends Model
{
    /** @use HasFactory<SignedLinkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }
}
