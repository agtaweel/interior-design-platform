<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
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
    use HasFactory, BelongsToOrganization, Auditable;

    /** Deliberately NOT in #[Fillable] above — these are only ever written by
     *  LeadController::convert(), never accepted directly from a client request body, same
     *  reasoning as Project's pricing cache columns (see that model's docblock). */
    protected function casts(): array
    {
        return [
            'estimated_budget' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function convertedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'converted_project_id');
    }
}
