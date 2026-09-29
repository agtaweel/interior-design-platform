<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No direct organization_id column: scoped indirectly through project_id ->
 * projects.organization_id, same convention as Contract/BoqItem/PricingRule (see Contract's
 * docblock). Lifecycle: draft -> sent -> partially_received -> received -> cancelled — see
 * PurchaseOrderController for the transition rules.
 */
#[Fillable(['project_id', 'supplier_id', 'po_number', 'status', 'notes', 'sent_at'])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, Auditable;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Auditable can't read organization_id off this model directly — resolve via the parent
     *  project, same pattern as Contract::auditOrganizationId(). */
    public function auditOrganizationId(): ?int
    {
        return $this->project?->organization_id;
    }
}
