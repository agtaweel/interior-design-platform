<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Maintain supplier/product price history with date and source." Auto-recorded (not a
 * separate manual-entry screen for MVP — BRD section 9 explicitly allows this: "Future AI can
 * flag unusual price increases; MVP should first capture clean historical data") whenever a
 * purchase order item's quoted or actual price is set, via SupplierPriceHistory::record() —
 * see PurchaseOrderController. organization_id is denormalized here (not derived through
 * supplier_id/project_id) purely for cheap tenant-scoped querying of a high-volume append-only
 * log, same rationale as audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('item_description');
            $table->string('unit');
            $table->decimal('unit_price', 14, 2);
            // quoted | actual — which side of the PO lifecycle produced this price point.
            $table->string('source');
            $table->foreignId('purchase_order_item_id')->nullable()
                ->constrained('purchase_order_items')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['supplier_id', 'item_description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_history');
    }
};
