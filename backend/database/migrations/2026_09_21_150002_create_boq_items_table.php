<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column here by design (same rationale as project_members/
     * project_services): this table is scoped indirectly through
     * project_id -> projects.organization_id.
     */
    public function up(): void
    {
        Schema::create('boq_items', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: line items are structural children of a project — deleting the
            // project removes its BOQ with it (same convention as rooms/boq_categories).
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // restrictOnDelete (not cascade): an item's category is commercial classification,
            // not disposable — deleting a category that still has priced items must be an
            // explicit action (reassign items first), matching the caution PROJECT_CONTEXT.md
            // applies to other commercial-adjacent FKs (e.g. properties.client_id).
            $table->foreignId('category_id')->constrained('boq_categories')->restrictOnDelete();
            // nullOnDelete: a room can be removed/renamed without destroying the priced items
            // that were assigned to it — they simply lose the room reference, same rationale as
            // projects.property_id.
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Precise measurement — numeric, never float.
            $table->decimal('quantity', 10, 2)->default(0);
            $table->string('unit');
            // Precise money values — numeric, never float, per PROJECT_CONTEXT.md money rules.
            $table->decimal('material_unit_cost', 12, 2)->default(0);
            $table->decimal('labor_unit_cost', 12, 2)->default(0);
            $table->decimal('other_unit_cost', 12, 2)->default(0);
            $table->decimal('client_unit_price', 12, 2)->default(0);
            // Deliberately NOT a foreign key: `suppliers` doesn't exist until Sprint 6. Plain
            // nullable column now; add the FK constraint in a follow-up migration once the
            // suppliers table lands, per PROJECT_CONTEXT.md's Sprint 2 scope decision.
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // Archive-not-delete: BOQ items may already be referenced by a sent proposal in
            // later sprints, so DELETE /boq/items/{id} sets this instead of a hard delete.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            // Recommended index (PROJECT_CONTEXT.md "Recommended indexes"): boq_items(project_id, category_id).
            $table->index(['project_id', 'category_id']);
            // Additional lookup pattern for the BOQ Builder screen: filter/group by room too.
            $table->index(['project_id', 'room_id']);
            $table->index('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boq_items');
    }
};
