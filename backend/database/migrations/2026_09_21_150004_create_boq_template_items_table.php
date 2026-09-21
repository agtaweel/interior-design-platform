<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Directly organization-scoped, same as boq_template_categories — not project-scoped, and
     * deliberately has no quantity/room_id columns since a template line isn't tied to any
     * specific project's rooms (see PROJECT_CONTEXT.md Sprint 2 "Templates").
     */
    public function up(): void
    {
        Schema::create('boq_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // restrictOnDelete: same commercial-caution rationale as boq_items.category_id —
            // deleting a template category that still has template items must be explicit.
            $table->foreignId('category_id')->constrained('boq_template_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit');
            // Precise money values — numeric, never float.
            $table->decimal('material_unit_cost', 12, 2)->default(0);
            $table->decimal('labor_unit_cost', 12, 2)->default(0);
            $table->decimal('other_unit_cost', 12, 2)->default(0);
            $table->decimal('client_unit_price', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['organization_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boq_template_items');
    }
};
