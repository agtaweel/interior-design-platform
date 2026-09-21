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
        Schema::create('boq_categories', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: categories are structural children of a project — deleting the
            // project removes its category tree too (same convention as rooms/project_members).
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // Self-referential for nested categories (e.g. "Flooring" > "Tiling"). nullOnDelete:
            // deleting a parent category should promote its children to top-level rather than
            // cascading further deletes into a subtree that may still hold priced items.
            $table->foreignId('parent_id')->nullable()->constrained('boq_categories')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('project_id');
            // Recommended index for this sprint's BOQ Builder screen: it groups/filters
            // categories by project and walks the parent/child tree together.
            $table->index(['project_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boq_categories');
    }
};
