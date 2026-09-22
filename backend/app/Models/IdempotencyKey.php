<?php

namespace App\Models;

use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * No organization scoping and no relations: rows are looked up solely by the (scope, key)
 * unique pair, independent of tenancy — same rationale as SignedLink/OtpChallenge. Write-once/
 * append-only: no updated_at column (see migration), rows should never be mutated after
 * insert, only read back for replay.
 *
 * Behavior (PROJECT_CONTEXT.md Sprint 4 scope, "OTP + idempotency mechanism"): if an incoming
 * request's `Idempotency-Key` header matches a stored row for that scope, the caller
 * (backend-api-engineer's controller) replays `response_status`/`response_body` verbatim
 * regardless of current state, rather than re-running the action or applying normal
 * state-conflict rules.
 */
#[Fillable([
    'scope', 'key', 'response_status', 'response_body',
])]
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
