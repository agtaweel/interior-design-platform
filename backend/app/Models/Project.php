<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'client_id', 'property_id', 'code', 'name', 'status',
    'start_date', 'target_end_date', 'responsible_user_id',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, BelongsToOrganization, Auditable;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'target_end_date' => 'date',
            // Sprint 3 pricing cache columns (see
            // 2026_09_22_000002_add_pricing_cache_columns_to_projects_table.php). Nullable with
            // no default: null means "never priced yet", distinct from a legitimately computed
            // 0 — decimal:2 preserves that null rather than coercing it to "0.00". Deliberately
            // NOT added to #[Fillable] above: these are only ever written by the recalculation
            // action (backend-api-engineer), never by a general project create/update request.
            'direct_cost_total' => 'decimal:2',
            'client_subtotal' => 'decimal:2',
            'markup_total' => 'decimal:2',
            'fees_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'priced_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProjectService::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function boqCategories(): HasMany
    {
        return $this->hasMany(BoqCategory::class);
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function proposalVersions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
