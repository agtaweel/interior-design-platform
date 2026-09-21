<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'name', 'phone', 'email', 'source', 'status',
    'estimated_budget', 'notes', 'owner_id',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'estimated_budget' => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
