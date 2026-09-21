<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'client_id', 'type', 'compound', 'address',
    'area_m2', 'bedrooms', 'bathrooms', 'metadata_json',
])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, BelongsToOrganization, Auditable;

    protected function casts(): array
    {
        return [
            'area_m2' => 'decimal:2',
            'metadata_json' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
