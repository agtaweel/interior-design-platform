<?php

namespace App\Models;

use Database\Factories\OtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No organization scoping at all (not even indirect): reached only via signed_link_id -> the
 * associated entity's tenant, and only ever touched by token-authenticated public-endpoint
 * code (never by tenant-scoped internal queries) — same rationale as App\Models\SignedLink
 * itself.
 *
 * `code_hash` mirrors SignedLink::token_hash: the raw OTP code is never persisted, only its
 * hash. Generic by design (not proposal-specific) — Sprint 7's change-order public approval
 * reuses this identical mechanism against its own signed_links row.
 */
#[Fillable([
    'signed_link_id', 'code_hash', 'expires_at', 'verified_at', 'attempts',
])]
class OtpChallenge extends Model
{
    /** @use HasFactory<OtpChallengeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function signedLink(): BelongsTo
    {
        return $this->belongsTo(SignedLink::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
