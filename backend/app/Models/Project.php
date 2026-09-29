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
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'organization_id', 'client_id', 'property_id', 'code', 'name', 'status',
    'start_date', 'target_end_date', 'responsible_user_id',
])]
class Project extends Model implements HasMedia
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, BelongsToOrganization, Auditable, InteractsWithMedia;

    /**
     * Attachments (Documents tab, PROJECT_CONTEXT.md "Documents" placeholder): three fixed
     * collections a project can attach files to. `singleFile()` is deliberately NOT used on any
     * of these — a project accumulates many designs/progress photos/final shots over its
     * lifetime, unlike e.g. a payment's one receipt. Stored on the `local` (private) disk, same
     * posture as payment receipts (PaymentController::receipt()) — served only through an
     * authenticated, tenant-checked download route, never a public/guessable URL, since design
     * files can be commercially sensitive.
     */
    public const MEDIA_COLLECTIONS = ['designs', 'process', 'final_pictures'];

    /**
     * BRD v3 §12 "Reconciliation & Closeout" state machine — see the closeout migration's
     * docblock for why this is a separate dimension from `status`. Transitions are owned
     * entirely by App\Services\Closeout\ProjectCloseoutService, never set directly here.
     */
    public const FINANCIAL_STATUS_ACTIVE = 'active';

    public const FINANCIAL_STATUS_FINANCIAL_PENDING = 'financial_pending';

    public const FINANCIAL_STATUS_READY_FOR_CLOSE = 'ready_for_close';

    public const FINANCIAL_STATUS_CLOSED = 'closed';

    public function registerMediaCollections(): void
    {
        foreach (self::MEDIA_COLLECTIONS as $collection) {
            $this->addMediaCollection($collection);
        }
    }

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
            // BRD v3 §12 closeout columns (see the migration's docblock) — deliberately NOT
            // added to #[Fillable] above: only ever written by ProjectCloseoutService via
            // forceFill(), never through the general PATCH /projects/{id} endpoint, same
            // "system-controlled state, not client input" reasoning as the pricing cache
            // columns above.
            'financial_closed_at' => 'datetime',
            'force_closed' => 'boolean',
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

    /**
     * Sprint 7: a project's change-order history, scoped indirectly the same one-hop way as
     * boqItems()/pricingRules()/proposalVersions()/contracts() above.
     */
    public function changeOrders(): HasMany
    {
        return $this->hasMany(ChangeOrder::class);
    }

    /**
     * Sprint 6: direct relation, since payments carry project_id directly (unlike
     * payment_schedules, which is only reachable from a project via contracts ->
     * paymentSchedules). This is what backs project-wide payment queries independent of which
     * contract/schedule a payment is against.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
