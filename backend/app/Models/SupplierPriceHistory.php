<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SupplierPriceHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BRD "Maintain supplier/product price history with date and source" — append-only, auto
 * recorded by PurchaseOrderController (see record() below), never edited or deleted through
 * the API. Deliberately no Auditable trait: this table IS already an immutable historical log,
 * not a mutable record that needs a separate before/after trail.
 */
#[Fillable([
    'organization_id', 'supplier_id', 'item_description', 'unit', 'unit_price', 'source',
    'purchase_order_item_id', 'recorded_at',
])]
class SupplierPriceHistory extends Model
{
    /** @use HasFactory<SupplierPriceHistoryFactory> */
    use HasFactory, BelongsToOrganization;

    // Eloquent's default pluralization would guess "supplier_price_histories" ("history" ->
    // "histories") — the migration deliberately named the table "supplier_price_history"
    // (matching the BRD's own wording), so this must be spelled out explicitly.
    protected $table = 'supplier_price_history';

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Records one price point. `source` is 'quoted' or 'actual' — see
     * PurchaseOrderController::store()/receive() for the two call sites.
     */
    public static function record(PurchaseOrderItem $item, string $source, string $unitPrice): self
    {
        return self::create([
            'organization_id' => $item->purchaseOrder->project->organization_id,
            'supplier_id' => $item->purchaseOrder->supplier_id,
            'item_description' => $item->description,
            'unit' => $item->unit,
            'unit_price' => $unitPrice,
            'source' => $source,
            'purchase_order_item_id' => $item->id,
            'recorded_at' => now(),
        ]);
    }
}
