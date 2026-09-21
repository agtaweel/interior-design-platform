<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unlike boq_categories, this table carries organization_id directly — it is NOT
     * project-scoped. Per PROJECT_CONTEXT.md's Sprint 2 "Templates" resolution: office-wide
     * templates live at the organization level and are copied (not referenced) into a
     * project's boq_categories/boq_items when "apply template to project" runs, so that
     * editing the cloned project BOQ never mutates the master template.
     */
    public function up(): void
    {
        Schema::create('boq_template_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Self-referential for nested template categories, same nullOnDelete rationale as
            // boq_categories.parent_id.
            $table->foreignId('parent_id')->nullable()->constrained('boq_template_categories')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['organization_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boq_template_categories');
    }
};
