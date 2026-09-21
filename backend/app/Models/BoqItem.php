<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\BoqItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No direct organization_id column: this table is scoped indirectly through
 * project_id -> projects.organization_id, same convention as ProjectMember/ProjectService.
 *
 * `supplier_id` deliberately has no FK constraint yet — `suppliers` doesn't exist until
 * Sprint 6 (see PROJECT_CONTEXT.md Sprint 2 scope). Add the constraint in a follow-up
 * migration once that table lands.
 *
 * Archived (not hard-deleted) via `archived_at` — BOQ items may already be referenced by a
 * sent proposal in later sprints. Nothing reads `archived_at` yet; that filtering belongs to
 * backend-api-engineer's controllers/queries.
 *
 * direct_cost/client_total below are pure per-item calculations (PROJECT_CONTEXT.md Sprint 2
 * "Calculations"); they are NOT organization-wide markup/pricing (that's Sprint 3's
 * pricing_rules) and they are NOT API response shaping — just the arithmetic the API layer
 * will need.
 */
#[Fillable([
    'project_id', 'category_id', 'room_id', 'name', 'description', 'quantity', 'unit',
    'material_unit_cost', 'labor_unit_cost', 'other_unit_cost', 'client_unit_price',
    'supplier_id', 'notes', 'sort_order', 'archived_at',
])]
class BoqItem extends Model
{
    /** @use HasFactory<BoqItemFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'material_unit_cost' => 'decimal:2',
            'labor_unit_cost' => 'decimal:2',
            'other_unit_cost' => 'decimal:2',
            'client_unit_price' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BoqCategory::class, 'category_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Auditable can't read organization_id off this model directly (no such column) — resolve
     * it via the parent project instead, per Auditable's documented override contract.
     */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }

    /**
     * (material + labor + other unit cost) * quantity — internal cost, never exposed to
     * client-facing/public serializers (PROJECT_CONTEXT.md Sprint 2 "Client-facing exposure").
     * Computed on the fly (not a stored column) using bcmath to stay decimal-exact — never
     * float — per PROJECT_CONTEXT.md's money rules.
     */
    protected function directCost(): Attribute
    {
        return Attribute::make(
            get: fn (): string => bcmul(
                bcadd(
                    bcadd((string) $this->material_unit_cost, (string) $this->labor_unit_cost, 2),
                    (string) $this->other_unit_cost,
                    2
                ),
                (string) $this->quantity,
                2
            ),
        );
    }

    /**
     * client_unit_price * quantity — the client-facing total for this line item. Computed on
     * the fly (not a stored column), same bcmath-exactness rationale as directCost().
     */
    protected function clientTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): string => bcmul((string) $this->client_unit_price, (string) $this->quantity, 2),
        );
    }
}
