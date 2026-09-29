<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Record quoted price, ordered price and actual received cost separately." quoted_unit_price
 * is set at PO creation/send time; received_quantity/actual_unit_price are only populated once
 * (part of) the line has actually been delivered — see PurchaseOrderController::receive().
 * NUMERIC (via decimal columns), never float, matching this codebase's money-handling rule
 * everywhere else (BoqItem, PricingRule, Payment, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('description');
            $table->string('unit');
            $table->decimal('quantity', 14, 3);
            $table->decimal('quoted_unit_price', 14, 2);
            // Nullable/zero-default: unset until PurchaseOrderController::receive() records an
            // actual delivery against this line — see that controller's docblock.
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->decimal('actual_unit_price', 14, 2)->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
