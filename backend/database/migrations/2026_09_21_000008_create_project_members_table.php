<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column here by design: this table is scoped through
     * project_id -> projects.organization_id (see ProjectMember model docblock).
     */
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Project-level role label (e.g. lead_designer, site_manager, viewer) — distinct
            // from the org-wide RBAC `roles` table; kept as a plain string per ERD field list.
            $table->string('role');
            $table->timestamps();

            $table->index('project_id');
            $table->index('user_id');
            $table->unique(['project_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
