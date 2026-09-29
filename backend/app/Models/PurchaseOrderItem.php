<?php

namespace App\Models;

use Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BRD "Record quoted price, ordered price and actual received cost separately" — see
 * PurchaseOrderController::receive() for how received_quantity/actual_unit_price get set.
 *
 * Not Auditable, deliberately — same reasoning as ChangeOrderItem's identical omission: the
 * parent PurchaseOrder's own Auditable trail already covers this table's row-level churn
 * (created once at PO creation, then updated once at receipt), auditing each line separately
 * would just be noise on top of that.
 */
#[Fillable(['purchase_order_id', 'description', 'unit', 'quantity', 'quoted_unit_price'])]
class PurchaseOrderItem extends Model
{
    /** @use HasFactory<PurchaseOrderItemFactory> */
    use HasFactory;

    /** received_quantity/actual_unit_price deliberately excluded from #[Fillable] above — only
     *  ever written by PurchaseOrderController::receive() via forceFill(), never accepted
     *  directly from a create/update request body (same reasoning as Lead's converted_* columns). */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'quoted_unit_price' => 'decimal:2',
            'received_quantity' => 'decimal:3',
            'actual_unit_price' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function quotedTotal(): string
    {
        return bcmul((string) $this->quantity, (string) $this->quoted_unit_price, 2);
    }

    public function actualTotal(): ?string
    {
        if ($this->actual_unit_price === null) {
            return null;
        }

        return bcmul((string) $this->received_quantity, (string) $this->actual_unit_price, 2);
    }
}
