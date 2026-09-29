<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Procurement": PO lifecycle draft -> sent -> partially_received -> received -> cancelled.
 * project_id (not organization_id directly) is the tenant-scoping anchor — same indirect
 * pattern as payment_schedules/contracts (see those migrations' docblocks); PurchaseOrder
 * resolves its organization by walking project_id -> projects.organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            // Human-facing sequential number (e.g. "PO-00001"), unique per project — mirrors
            // projects.code's own auto-generated-but-overridable convention.
            $table->string('po_number');
            // draft | sent | partially_received | received | cancelled
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'po_number']);
            $table->index('project_id');
            $table->index('supplier_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
