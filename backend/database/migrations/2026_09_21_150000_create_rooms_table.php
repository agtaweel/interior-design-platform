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
     * project_id -> projects.organization_id. See the Room model docblock for how
     * Auditable-style organization resolution would work if ever needed (Room is not
     * audited — see model docblock).
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: rooms are purely structural children of a project — when a
            // project is deleted its room list goes with it (same convention as
            // project_members/project_services cascading on project_id).
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            // Precise measurement — numeric, never float. Matches properties.area_m2 precision.
            $table->decimal('area_m2', 10, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
