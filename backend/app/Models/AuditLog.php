<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: no updated_at column, rows should never be mutated after insert.
 */
#[Fillable([
    'organization_id', 'actor_user_id', 'entity_type', 'entity_id', 'action',
    'before_json', 'after_json', 'ip_address',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, BelongsToOrganization;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
